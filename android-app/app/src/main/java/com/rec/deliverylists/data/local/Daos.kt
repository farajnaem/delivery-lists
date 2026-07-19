package com.rec.deliverylists.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Transaction
import kotlinx.coroutines.flow.Flow

@Dao
interface CampaignDao {
    @Query("SELECT * FROM campaigns ORDER BY name")
    fun observeAll(): Flow<List<CampaignEntity>>

    @Query("SELECT * FROM campaigns WHERE id = :id LIMIT 1")
    suspend fun get(id: Int): CampaignEntity?

    @Query("SELECT * FROM campaigns WHERE id = :id LIMIT 1")
    fun observe(id: Int): Flow<CampaignEntity?>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<CampaignEntity>)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(item: CampaignEntity)

    @Query("UPDATE campaigns SET lastSyncToken = :token, lastSyncAt = :at, snapshotComplete = :complete WHERE id = :id")
    suspend fun updateSyncMeta(id: Int, token: String?, at: Long?, complete: Boolean)

    @Query("UPDATE campaigns SET openingQuantity = :opening, delivered = :delivered, balance = :balance, pending = :pending, campaignActive = :active, deliveryClosedAt = :closedAt WHERE id = :id")
    suspend fun updateStock(id: Int, opening: Int, delivered: Int, balance: Int, pending: Int, active: Boolean, closedAt: String?)
}

@Dao
interface BeneficiaryDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<BeneficiaryEntity>)

    @Query("SELECT * FROM beneficiaries WHERE campaignId = :campaignId AND id = :id LIMIT 1")
    suspend fun get(campaignId: Int, id: Int): BeneficiaryEntity?

    @Query("""
        SELECT * FROM beneficiaries
        WHERE campaignId = :campaignId
          AND (
            displayCode = :queryNorm
            OR UPPER(disbursementCode) = UPPER(:queryNorm)
            OR nationalId = :query
            OR REPLACE(nationalId, ' ', '') = :queryNorm
            OR name LIKE '%' || :query || '%'
          )
        LIMIT 20
    """)
    suspend fun search(campaignId: Int, query: String, queryNorm: String): List<BeneficiaryEntity>

    @Query("UPDATE beneficiaries SET receiptStatus = :status, deliveredAt = :deliveredAt, deliveryType = :type WHERE id = :id AND campaignId = :campaignId")
    suspend fun markLocalDelivered(campaignId: Int, id: Int, status: String, deliveredAt: String?, type: String?)

    @Query("DELETE FROM beneficiaries WHERE campaignId = :campaignId")
    suspend fun deleteForCampaign(campaignId: Int)
}

@Dao
interface PendingDeliveryDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(item: PendingDeliveryEntity)

    @Query("SELECT * FROM pending_deliveries WHERE campaignId = :campaignId AND syncStatus = 'pending' ORDER BY queuedAt")
    suspend fun pendingForCampaign(campaignId: Int): List<PendingDeliveryEntity>

    @Query("SELECT COUNT(*) FROM pending_deliveries WHERE syncStatus = 'pending'")
    fun observePendingCount(): Flow<Int>

    @Query("DELETE FROM pending_deliveries WHERE clientId = :clientId")
    suspend fun delete(clientId: String)

    @Query("DELETE FROM pending_deliveries WHERE campaignId = :campaignId AND syncStatus = 'pending'")
    suspend fun clearPendingForCampaign(campaignId: Int)
}

@Dao
interface CacheDao {
    @Query("DELETE FROM recent_delivered_cache WHERE campaignId = :campaignId")
    suspend fun clearRecent(campaignId: Int)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertRecent(items: List<RecentDeliveredEntity>)

    @Query("SELECT * FROM recent_delivered_cache WHERE campaignId = :campaignId ORDER BY rowId LIMIT :limit")
    fun observeRecent(campaignId: Int, limit: Int = 50): Flow<List<RecentDeliveredEntity>>

    @Query("DELETE FROM late_cache WHERE campaignId = :campaignId")
    suspend fun clearLate(campaignId: Int)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertLate(items: List<LateEntity>)

    @Query("SELECT * FROM late_cache WHERE campaignId = :campaignId LIMIT :limit")
    fun observeLate(campaignId: Int, limit: Int = 100): Flow<List<LateEntity>>
}
