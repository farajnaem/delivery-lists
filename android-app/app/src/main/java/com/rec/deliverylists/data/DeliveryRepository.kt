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
import com.rec.deliverylists.data.remote.LoginRequest
import com.rec.deliverylists.data.remote.PendingDeliveryItem
import com.rec.deliverylists.data.remote.RecentDeliveredDto
import com.rec.deliverylists.data.remote.StockDto
import com.rec.deliverylists.data.remote.SyncRequest
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import retrofit2.HttpException
import com.google.gson.Gson
import com.google.gson.JsonObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

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
            throw IllegalStateException(res.error ?: "فشل تسجيل الدخول")
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

    suspend fun downloadSnapshot(campaignId: Int): Result<Unit> = apiCall {
        requireToken()
        val snap = api.snapshot(campaignId)
        if (!snap.ok) throw IllegalStateException(snap.error ?: "فشل التحميل")

        beneficiaryDao.deleteForCampaign(campaignId)
        beneficiaryDao.upsertAll(snap.beneficiaries.map { it.toEntity() })

        snap.campaign?.let { campaignDao.upsert(it.toEntity(null).copy(
            snapshotComplete = true,
            lastSyncToken = snap.sync_token,
            lastSyncAt = System.currentTimeMillis(),
        )) }

        snap.stock?.let { updateStockLocal(campaignId, it, snap.campaign) }
        refreshCaches(campaignId, snap.recent_delivered, snap.late)
    }

    suspend fun syncCampaign(campaignId: Int): Result<String?> = apiCall {
        requireToken()
        val campaign = campaignDao.get(campaignId) ?: throw IllegalStateException("العملية غير محمّلة")
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

        res.updated_beneficiaries.forEach { dto ->
            beneficiaryDao.upsertAll(listOf(dto.toEntity()))
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
        res.sync_token
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

    suspend fun search(campaignId: Int, query: String): List<BeneficiaryEntity> {
        val q = query.trim()
        if (q.isEmpty()) return emptyList()
        val norm = q.replace(" ", "")
        return beneficiaryDao.search(campaignId, q, norm)
    }

    suspend fun confirmDelivery(campaignId: Int, beneficiary: BeneficiaryEntity): Result<Unit> = runCatching {
        val campaign = campaignDao.get(campaignId) ?: throw IllegalStateException("العملية غير موجودة")
        if (!campaign.campaignActive) throw IllegalStateException("تم إنهاء عملية التسليم")
        if (beneficiary.receiptStatus == STATUS_DELIVERED) throw IllegalStateException("مُسلَّم مسبقاً")
        if (campaign.balance <= 0) throw IllegalStateException("لا يوجد رصيد")

        val now = nowString()
        beneficiaryDao.markLocalDelivered(campaignId, beneficiary.id, STATUS_DELIVERED, now, "on_time")

        val clientId = UUID.randomUUID().toString()
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

    fun observeRecent(campaignId: Int) = cacheDao.observeRecent(campaignId)
    fun observeLate(campaignId: Int) = cacheDao.observeLate(campaignId)

    suspend fun getCampaign(id: Int) = campaignDao.get(id)

    private suspend fun requireToken(): String =
        session.tokenFlow.first()?.also { SessionStore.cachedToken = it }
            ?: throw IllegalStateException("سجّل الدخول أولاً")

    private suspend fun <T> apiCall(block: suspend () -> T): Result<T> = runCatching {
        block()
    }.recoverCatching { error ->
        if (error is HttpException && error.code() == 401 && shouldClearSession(error)) {
            session.clear()
            throw IllegalStateException("انتهت الجلسة — سجّل الدخول مجدداً")
        }
        throw IllegalStateException(readApiError(error))
    }

    private fun shouldClearSession(error: HttpException): Boolean {
        val body = error.response()?.errorBody()?.string() ?: return true
        return runCatching {
            val json = Gson().fromJson(body, JsonObject::class.java)
            val code = json.get("error_code")?.asString
            code == null || code == "auth_required"
        }.getOrDefault(true)
    }

    private fun readApiError(error: Throwable): String {
        if (error is IllegalStateException) return error.message ?: "خطأ"
        if (error is HttpException) {
            val body = error.response()?.errorBody()?.string()
            if (!body.isNullOrBlank()) {
                runCatching {
                    val json = Gson().fromJson(body, JsonObject::class.java)
                    json.get("error")?.asString?.takeIf { it.isNotBlank() }
                }.getOrNull()?.let { return it }
            }
            return when (error.code()) {
                401 -> "غير مصرّح — سجّل الدخول"
                403 -> "ليس لديك صلاحية"
                else -> "خطأ من السيرفر (${error.code()})"
            }
        }
        return error.message ?: "فشل الاتصال"
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
