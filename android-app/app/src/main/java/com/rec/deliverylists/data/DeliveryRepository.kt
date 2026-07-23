package com.rec.deliverylists.data

import com.rec.deliverylists.data.local.AppDatabase
import com.rec.deliverylists.data.local.BeneficiaryEntity
import com.rec.deliverylists.data.local.CampaignEntity
import com.rec.deliverylists.data.local.LateEntity
import com.rec.deliverylists.data.local.PendingDeliveryEntity
import com.rec.deliverylists.data.local.RecentDeliveredEntity
import com.rec.deliverylists.data.remote.ApiClient
import com.rec.deliverylists.data.remote.BeneficiaryDto
import com.rec.deliverylists.data.remote.CampaignDto
import com.rec.deliverylists.data.remote.DeliverRequest
import com.rec.deliverylists.data.remote.LoginRequest
import com.rec.deliverylists.data.remote.PendingDeliveryItem
import com.rec.deliverylists.data.remote.RecentDeliveredDto
import com.rec.deliverylists.data.remote.StockDto
import com.rec.deliverylists.data.remote.SyncRequest
import com.rec.deliverylists.data.remote.SyncResponse
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import retrofit2.HttpException
import com.rec.deliverylists.util.ArabicFormat
import com.google.gson.Gson
import com.google.gson.JsonObject
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID
import kotlin.coroutines.cancellation.CancellationException

data class ConfirmDeliveryResult(
    /** true = سُجّل على السيرفر فوراً */
    val online: Boolean,
)

class DeliveryRepository(
    private val db: AppDatabase,
    private val session: SessionStore,
) {
    private val api = ApiClient.api
    private val campaignDao = db.campaignDao()
    private val beneficiaryDao = db.beneficiaryDao()
    private val pendingDao = db.pendingDao()
    private val cacheDao = db.cacheDao()

    val campaignsFlow: Flow<List<CampaignEntity>> = campaignDao.observeAll()
    val pendingCountFlow: Flow<Int> = pendingDao.observePendingCount()

    fun getTokenFlow() = session.tokenFlow

    suspend fun getToken(): String? = session.tokenFlow.first()

    suspend fun login(email: String, password: String): Result<Unit> = apiCall {
        val res = api.login(LoginRequest(email.trim(), password))
        if (!res.ok || res.token.isNullOrBlank() || res.user == null) {
            throw IllegalStateException(res.error ?: "بيانات الدخول غير صحيحة")
        }
        session.save(res.token, res.user.name, res.user.email)
    }

    suspend fun logout() {
        session.clear()
    }

    suspend fun refreshCampaignList(): Result<Unit> = apiCall {
        requireToken()
        val res = api.campaigns()
        if (!res.ok) throw IllegalStateException(res.error ?: "فشل جلب العمليات")
        val hint = res.hint
        if (hint != null && res.campaigns.isEmpty()) {
            throw IllegalStateException(hint)
        }
        val existing = campaignDao.observeAll().first().associateBy { it.id }
        val mapped = res.campaigns.map { dto ->
            dto.toEntity(existing[dto.id])
        }
        campaignDao.upsertAll(mapped)
    }

    suspend fun downloadSnapshot(
        campaignId: Int,
        onProgress: ((Float, String) -> Unit)? = null,
    ): Result<Unit> = apiCall {
        requireToken()
        suspend fun report(fraction: Float, message: String) {
            if (onProgress == null) return
            kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.Main.immediate) {
                onProgress(fraction, message)
            }
        }

        kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
            report(0.05f, "جاري التنزيل من السيرفر…")
            val snap = api.snapshot(campaignId)
            if (!snap.ok) throw IllegalStateException(snap.error ?: "فشل تحميل بيانات الطرد من السيرفر")

            val total = snap.beneficiaries.size
            report(
                0.35f,
                if (total > 0) "تم الاستلام — جاري حفظ $total مستفيد…"
                else "تم الاستلام — جاري حفظ البيانات…",
            )

            beneficiaryDao.deleteForCampaign(campaignId)

            if (total == 0) {
                report(0.85f, "لا يوجد مستفيدون في هذه العملية")
            } else {
                val chunkSize = 200
                val chunks = snap.beneficiaries.chunked(chunkSize)
                chunks.forEachIndexed { index, chunk ->
                    beneficiaryDao.upsertAll(chunk.map { it.toEntity() })
                    val saved = minOf(total, (index + 1) * chunkSize)
                    val fraction = 0.35f + 0.50f * ((index + 1).toFloat() / chunks.size)
                    report(fraction, "حفظ المستفيدين: $saved / $total")
                    kotlinx.coroutines.yield()
                }
            }

            report(0.90f, "تحديث المخزن والسجلات…")
            snap.campaign?.let {
                campaignDao.upsert(
                    it.toEntity(null).copy(
                        snapshotComplete = true,
                        lastSyncToken = snap.sync_token,
                        lastSyncAt = System.currentTimeMillis(),
                    ),
                )
            }

            snap.stock?.let { updateStockLocal(campaignId, it, snap.campaign) }
            refreshCaches(campaignId, snap.recent_delivered, snap.late)
            report(1f, "اكتمل التحميل")
        }
    }

    suspend fun syncCampaign(
        campaignId: Int,
        onProgress: ((Float, String) -> Unit)? = null,
    ): Result<String?> = apiCall {
        requireToken()
        suspend fun report(fraction: Float, message: String) {
            if (onProgress == null) return
            kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.Main.immediate) {
                onProgress(fraction, message)
            }
        }

        kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
            report(0.15f, "مزامنة التسليمات مع السيرفر…")
            val campaign = campaignDao.get(campaignId)
                ?: throw IllegalStateException("العملية غير محمّلة")
            val pendingEntities = pendingDao.pendingForCampaign(campaignId)
            val pending = pendingEntities.map {
                PendingDeliveryItem(it.beneficiaryId, it.clientId)
            }

            val res = api.sync(
                SyncRequest(
                    campaign_id = campaignId,
                    last_sync_token = campaign.lastSyncToken,
                    pending_deliveries = pending,
                ),
            )
            if (!res.ok) throw IllegalStateException(res.error ?: "فشلت المزامنة")

            report(0.6f, "تطبيق التحديثات…")
            applySyncPayload(campaignId, res, pendingEntities)
            report(1f, "اكتملت المزامنة")
            res.sync_token
        }
    }

    suspend fun syncAllPending(): Result<Int> = apiCall {
        var synced = 0
        val campaigns = campaignDao.observeAll().first().filter { it.snapshotComplete }
        campaigns.forEach { c ->
            val before = pendingDao.pendingForCampaign(c.id).size
            syncCampaign(c.id).getOrThrow()
            val after = pendingDao.pendingForCampaign(c.id).size
            synced += maxOf(0, before - after)
        }
        synced
    }

    suspend fun search(campaignId: Int, query: String, refreshFromServer: Boolean = false): List<BeneficiaryEntity> {
        if (refreshFromServer) {
            // تحديث الحالة من السيرفر قبل البحث حتى يظهر «مستلم» من جهاز آخر
            runCatching { syncCampaign(campaignId).getOrThrow() }
        }
        val q = query.trim()
        if (q.isEmpty()) return emptyList()
        val norm = ArabicFormat.toWestern(q).replace(" ", "")
        return beneficiaryDao.search(campaignId, q, norm)
    }

    suspend fun getBeneficiary(campaignId: Int, id: Int): BeneficiaryEntity? =
        beneficiaryDao.get(campaignId, id)

    /**
     * أونلاين أولاً: يسجّل على السيرفر فوراً لمنع التسليم المزدوج بين الجوالات.
     * بدون إنترنت: يسجّل محلياً ويُضاف لطابور المزامنة.
     */
    suspend fun confirmDelivery(campaignId: Int, beneficiary: BeneficiaryEntity): Result<ConfirmDeliveryResult> =
        runCatching {
            val campaign = campaignDao.get(campaignId) ?: throw IllegalStateException("العملية غير موجودة")
            if (!campaign.campaignActive) throw IllegalStateException("تم إنهاء عملية التسليم — لا يمكن التسجيل")
            if (beneficiary.receiptStatus == STATUS_DELIVERED) {
                throw IllegalStateException("هذا المستفيد مُسلَّم مسبقاً")
            }
            if (campaign.balance <= 0) throw IllegalStateException("لا يوجد رصيد متاح في المخزن")

            val clientId = UUID.randomUUID().toString()
            val online = tryConfirmOnline(campaignId, beneficiary, clientId)
            if (online) {
                ConfirmDeliveryResult(online = true)
            } else {
                applyOfflineDelivery(campaignId, campaign, beneficiary, clientId)
                ConfirmDeliveryResult(online = false)
            }
        }

    /**
     * @return true إذا نجح على السيرفر
     * @throws IllegalStateException لأخطاء العمل (مُسلَّم مسبقاً، رصيد، …)
     * @return false فقط عند انقطاع الشبكة → مسار أوفلاين
     */
    private suspend fun tryConfirmOnline(
        campaignId: Int,
        beneficiary: BeneficiaryEntity,
        clientId: String,
    ): Boolean {
        return try {
            requireToken()
            // فضّل endpoint التسليم الفوري؛ إن لم يكن على السيرفر بعد نستخدم sync
            val deliveredViaApi = tryDeliverEndpoint(campaignId, beneficiary.id, clientId)
            if (deliveredViaApi != null) {
                return when {
                    deliveredViaApi.ok -> true
                    deliveredViaApi.already -> {
                        deliveredViaApi.beneficiary?.let {
                            beneficiaryDao.upsertAll(listOf(it.toEntity()))
                        }
                        deliveredViaApi.stock?.let { updateStockLocal(campaignId, it, deliveredViaApi.campaign) }
                        refreshCaches(campaignId, deliveredViaApi.recent_delivered, deliveredViaApi.late)
                        if (deliveredViaApi.sync_token != null) {
                            campaignDao.updateSyncMeta(
                                campaignId,
                                deliveredViaApi.sync_token,
                                System.currentTimeMillis(),
                                true,
                            )
                        }
                        throw IllegalStateException(
                            deliveredViaApi.error ?: "تم تسليم هذا المستفيد مسبقاً من جهاز آخر",
                        )
                    }
                    else -> throw IllegalStateException(deliveredViaApi.error ?: "فشل تسجيل الاستلام على السيرفر")
                }
            }

            // مسار بديل: مزامنة بعنصر واحد (+ المعلّق سابقاً)
            val campaign = campaignDao.get(campaignId)
                ?: throw IllegalStateException("العملية غير محمّلة")
            val pendingEntities = pendingDao.pendingForCampaign(campaignId)
            val pending = pendingEntities.map {
                PendingDeliveryItem(it.beneficiaryId, it.clientId)
            } + PendingDeliveryItem(beneficiary.id, clientId)

            val res = api.sync(
                SyncRequest(
                    campaign_id = campaignId,
                    last_sync_token = campaign.lastSyncToken,
                    pending_deliveries = pending,
                ),
            )
            if (!res.ok) throw IllegalStateException(res.error ?: "فشلت المزامنة")

            applySyncPayload(campaignId, res, pendingEntities)

            val match = res.upload?.results?.find { row ->
                (row["beneficiary_id"] as? Number)?.toInt() == beneficiary.id
            }
            val ok = match?.get("ok") as? Boolean ?: false
            val already = match?.get("already") as? Boolean ?: false
            val error = match?.get("error") as? String

            when {
                ok -> {
                    // ضمان تحديث محلي حتى لو لم يأتِ في updated_beneficiaries
                    if (res.updated_beneficiaries.none { it.id == beneficiary.id }) {
                        beneficiaryDao.markLocalDelivered(
                            campaignId,
                            beneficiary.id,
                            STATUS_DELIVERED,
                            nowString(),
                            "on_time",
                        )
                    }
                    true
                }
                already -> throw IllegalStateException(
                    error ?: "تم تسليم هذا المستفيد مسبقاً من جهاز آخر",
                )
                else -> throw IllegalStateException(error ?: "فشل تسجيل الاستلام على السيرفر")
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: IllegalStateException) {
            throw e
        } catch (e: Throwable) {
            if (isNetworkError(e)) {
                false
            } else {
                throw IllegalStateException(readApiError(e))
            }
        }
    }

    /** null = الـ endpoint غير موجود (404) → استخدم sync */
    private suspend fun tryDeliverEndpoint(
        campaignId: Int,
        beneficiaryId: Int,
        clientId: String,
    ): com.rec.deliverylists.data.remote.DeliverResponse? {
        return try {
            val res = api.deliver(
                DeliverRequest(
                    campaign_id = campaignId,
                    beneficiary_id = beneficiaryId,
                    client_id = clientId,
                ),
            )
            if (res.ok) {
                res.beneficiary?.let { beneficiaryDao.upsertAll(listOf(it.toEntity())) }
                    ?: beneficiaryDao.markLocalDelivered(
                        campaignId,
                        beneficiaryId,
                        STATUS_DELIVERED,
                        nowString(),
                        "on_time",
                    )
                res.stock?.let { updateStockLocal(campaignId, it, res.campaign) }
                refreshCaches(campaignId, res.recent_delivered, res.late)
                if (res.sync_token != null) {
                    campaignDao.updateSyncMeta(campaignId, res.sync_token, System.currentTimeMillis(), true)
                }
            }
            res
        } catch (e: HttpException) {
            if (e.code() == 404) null
            else {
                // 400 مع already:true يُعاد كـ HTTP 200 من السيرفر الجديد؛ إن رجع 400 نقرأ الجسم
                val body = e.response()?.errorBody()?.string()
                if (!body.isNullOrBlank()) {
                    runCatching {
                        Gson().fromJson(body, com.rec.deliverylists.data.remote.DeliverResponse::class.java)
                    }.getOrNull()?.let { return it }
                }
                throw e
            }
        }
    }

    private suspend fun applyOfflineDelivery(
        campaignId: Int,
        campaign: CampaignEntity,
        beneficiary: BeneficiaryEntity,
        clientId: String,
    ) {
        val now = nowString()
        beneficiaryDao.markLocalDelivered(campaignId, beneficiary.id, STATUS_DELIVERED, now, "on_time")
        pendingDao.insert(
            PendingDeliveryEntity(
                clientId = clientId,
                campaignId = campaignId,
                beneficiaryId = beneficiary.id,
                beneficiaryName = beneficiary.name,
                displayCode = beneficiary.displayCode,
                queuedAt = System.currentTimeMillis(),
            ),
        )
        cacheDao.insertRecent(
            listOf(
                RecentDeliveredEntity(
                    campaignId = campaignId,
                    displayCode = beneficiary.displayCode,
                    name = beneficiary.name,
                    deliveredAt = now,
                    deliveryType = "on_time",
                    sortOrder = beneficiary.sortOrder,
                ),
            ),
        )
        campaignDao.updateStock(
            campaignId,
            campaign.openingQuantity,
            campaign.delivered + 1,
            maxOf(0, campaign.balance - 1),
            maxOf(0, campaign.pending - 1),
            campaign.campaignActive,
            campaign.deliveryClosedAt,
        )
    }

    private suspend fun applySyncPayload(
        campaignId: Int,
        res: SyncResponse,
        pendingEntities: List<PendingDeliveryEntity>,
    ) {
        if (res.updated_beneficiaries.isNotEmpty()) {
            beneficiaryDao.upsertAll(res.updated_beneficiaries.map { it.toEntity() })
        }

        pendingEntities.forEach { item ->
            val match = res.upload?.results?.find {
                (it["beneficiary_id"] as? Number)?.toInt() == item.beneficiaryId
            }
            val ok = match?.get("ok") as? Boolean ?: false
            val already = match?.get("already") as? Boolean ?: false
            if (ok || already) {
                pendingDao.delete(item.clientId)
            }
        }

        res.stock?.let { updateStockLocal(campaignId, it, res.campaign) }
        refreshCaches(campaignId, res.recent_delivered, res.late)
        campaignDao.updateSyncMeta(campaignId, res.sync_token, System.currentTimeMillis(), true)
    }

    fun observeRecent(campaignId: Int) = cacheDao.observeRecent(campaignId)
    fun observeLate(campaignId: Int) = cacheDao.observeLate(campaignId)

    suspend fun getCampaign(id: Int) = campaignDao.get(id)

    fun observeCampaign(id: Int): Flow<CampaignEntity?> = campaignDao.observe(id)

    private fun isNetworkError(error: Throwable): Boolean {
        var cur: Throwable? = error
        while (cur != null) {
            when (cur) {
                is UnknownHostException,
                is SocketTimeoutException,
                is ConnectException,
                is IOException,
                -> return true
            }
            val name = cur.javaClass.simpleName
            val msg = cur.message.orEmpty()
            if (
                name.contains("UnknownHost", true) ||
                name.contains("SocketTimeout", true) ||
                name.contains("ConnectException", true) ||
                msg.contains("Unable to resolve host", true) ||
                msg.contains("failed to connect", true) ||
                msg.contains("timeout", true)
            ) {
                return true
            }
            cur = cur.cause
        }
        return false
    }

    private suspend fun requireToken(): String =
        session.tokenFlow.first()?.also { SessionStore.cachedToken = it }
            ?: throw IllegalStateException("سجّل الدخول أولاً")

    private suspend fun <T> apiCall(block: suspend () -> T): Result<T> {
        return try {
            Result.success(block())
        } catch (error: CancellationException) {
            throw error
        } catch (error: Throwable) {
            if (error is HttpException && error.code() == 401 && shouldClearSession(error)) {
                session.clear()
                Result.failure(
                    IllegalStateException(
                        readApiError(error).ifBlank {
                            "انتهت صلاحية الجلسة — سجّل الدخول مجدداً (قد يحدث بعد تحديث السيرفر)"
                        },
                    ),
                )
            } else {
                Result.failure(IllegalStateException(readApiError(error)))
            }
        }
    }

    /**
     * نمسح الجلسة فقط عند تأكيد السيرفر أن التوكن مرفوض (auth_required).
     * أي 401 فارغ/غامض (بروكسي) لا يمسح الجلسة حتى لا يُطرد المستخدم ظلماً.
     */
    private fun shouldClearSession(error: HttpException): Boolean {
        val body = error.response()?.errorBody()?.string().orEmpty()
        if (body.isBlank()) return false
        return runCatching {
            val json = Gson().fromJson(body, JsonObject::class.java)
            json.get("error_code")?.asString == "auth_required"
        }.getOrDefault(false)
    }

    private fun readApiError(error: Throwable): String {
        if (error is IllegalStateException) return error.message ?: "حدث خطأ غير متوقع"
        if (error is HttpException) {
            val body = error.response()?.errorBody()?.string()
            if (!body.isNullOrBlank()) {
                runCatching {
                    val json = Gson().fromJson(body, JsonObject::class.java)
                    json.get("error")?.asString?.takeIf { it.isNotBlank() }
                }.getOrNull()?.let { return it }
            }
            return when (error.code()) {
                401 -> "انتهت صلاحية الجلسة — سجّل الدخول مجدداً (قد يحدث بعد تحديث السيرفر)"
                403 -> "ليس لديك صلاحية لهذا الإجراء"
                404 -> "البيانات غير موجودة على السيرفر"
                408, 504 -> "انتهت مهلة الاتصال — حاول مرة أخرى"
                500, 502, 503 -> "السيرفر غير متاح حالياً — حاول لاحقاً"
                else -> "تعذّر الاتصال بالسيرفر (${error.code()})"
            }
        }
        val name = error.javaClass.simpleName
        val msg = error.message.orEmpty()
        return when {
            name.contains("UnknownHost", ignoreCase = true) ||
                msg.contains("Unable to resolve host", ignoreCase = true) ->
                "لا يوجد اتصال بالإنترنت"

            name.contains("SocketTimeout", ignoreCase = true) ||
                msg.contains("timeout", ignoreCase = true) ->
                "انتهت مهلة الاتصال — تحقق من الشبكة"

            name.contains("ConnectException", ignoreCase = true) ||
                msg.contains("failed to connect", ignoreCase = true) ->
                "تعذّر الوصول للسيرفر — تحقق من الإنترنت"

            msg.isNotBlank() -> msg
            else -> "فشل الاتصال — حاول مرة أخرى"
        }
    }

    private suspend fun updateStockLocal(campaignId: Int, stock: StockDto, campaign: CampaignDto?) {
        val c = campaignDao.get(campaignId)
        campaignDao.updateStock(
            campaignId,
            stock.opening_quantity,
            stock.delivered,
            stock.balance,
            stock.pending,
            stock.campaign_active,
            campaign?.delivery_closed_at,
        )
        if (c != null) {
            campaignDao.upsert(
                c.copy(
                    openingQuantity = stock.opening_quantity,
                    delivered = stock.delivered,
                    balance = stock.balance,
                    pending = stock.pending,
                    campaignActive = stock.campaign_active,
                    deliveryClosedAt = campaign?.delivery_closed_at,
                ),
            )
        }
    }

    private suspend fun refreshCaches(
        campaignId: Int,
        recent: List<RecentDeliveredDto>,
        late: List<RecentDeliveredDto>,
    ) {
        cacheDao.clearRecent(campaignId)
        cacheDao.insertRecent(
            recent.map {
                RecentDeliveredEntity(
                    campaignId = campaignId,
                    displayCode = it.display_code ?: it.sort_order?.toString() ?: it.disbursement_code ?: "",
                    name = it.name,
                    deliveredAt = it.delivered_at,
                    deliveryType = it.delivery_type,
                    sortOrder = it.sort_order ?: 0,
                )
            },
        )
        cacheDao.clearLate(campaignId)
        cacheDao.insertLate(
            late.map {
                LateEntity(
                    beneficiaryId = it.id ?: 0,
                    campaignId = campaignId,
                    displayCode = it.display_code ?: it.sort_order?.toString() ?: "",
                    name = it.name,
                    deliveryDate = it.delivery_date,
                    windowNum = it.window_num,
                )
            },
        )
    }

    private fun CampaignDto.toEntity(prev: CampaignEntity?): CampaignEntity = CampaignEntity(
        id = id,
        name = name,
        parcelName = parcel_name,
        warehouseName = warehouse_name,
        deliveryStart = delivery_start,
        deliveryEnd = delivery_end,
        deliveryClosedAt = delivery_closed_at,
        campaignActive = campaign_active,
        openingQuantity = stock?.opening_quantity ?: prev?.openingQuantity ?: 0,
        delivered = stock?.delivered ?: prev?.delivered ?: delivered_count,
        balance = stock?.balance ?: prev?.balance ?: 0,
        pending = stock?.pending ?: prev?.pending ?: 0,
        beneficiaryCount = beneficiary_count,
        lastSyncToken = sync_token ?: prev?.lastSyncToken,
        snapshotComplete = prev?.snapshotComplete ?: false,
        lastSyncAt = prev?.lastSyncAt,
    )

    private fun BeneficiaryDto.toEntity() = BeneficiaryEntity(
        id = id,
        campaignId = campaign_id,
        name = name,
        nationalId = national_id,
        mobile = mobile,
        receiptStatus = receipt_status,
        disbursementCode = disbursement_code,
        displayCode = display_code ?: sort_order.toString(),
        sortOrder = sort_order,
        deliveryDate = delivery_date,
        windowNum = window_num,
        timeFrom = time_from,
        timeTo = time_to,
        deliveredAt = delivered_at,
        deliveryType = delivery_type,
        updatedAt = updated_at,
    )

    companion object {
        const val STATUS_DELIVERED = "مستلم"
        const val STATUS_PENDING = "قيد التسليم"

        private fun nowString(): String =
            SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date())
    }
}
