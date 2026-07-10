package com.rec.deliverylists.data.local

import androidx.room.Database
import androidx.room.RoomDatabase

@Database(
    entities = [
        CampaignEntity::class,
        BeneficiaryEntity::class,
        PendingDeliveryEntity::class,
        RecentDeliveredEntity::class,
        LateEntity::class,
    ],
    version = 1,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun campaignDao(): CampaignDao
    abstract fun beneficiaryDao(): BeneficiaryDao
    abstract fun pendingDao(): PendingDeliveryDao
    abstract fun cacheDao(): CacheDao
}
