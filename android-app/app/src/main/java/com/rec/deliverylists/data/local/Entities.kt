package com.rec.deliverylists.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "campaigns")
data class CampaignEntity(
    @PrimaryKey val id: Int,
    val name: String,
    val parcelName: String,
    val warehouseName: String,
    val deliveryStart: String,
    val deliveryEnd: String,
    val deliveryClosedAt: String? = null,
    val campaignActive: Boolean = true,
    val openingQuantity: Int = 0,
    val delivered: Int = 0,
    val balance: Int = 0,
    val pending: Int = 0,
    val beneficiaryCount: Int = 0,
    val lastSyncToken: String? = null,
    val snapshotComplete: Boolean = false,
    val lastSyncAt: Long? = null,
)

@Entity(tableName = "beneficiaries")
data class BeneficiaryEntity(
    @PrimaryKey val id: Int,
    val campaignId: Int,
    val name: String,
    val nationalId: String,
    val mobile: String,
    val receiptStatus: String,
    val disbursementCode: String?,
    val displayCode: String,
    val sortOrder: Int,
    val deliveryDate: String?,
    val windowNum: Int?,
    val timeFrom: String?,
    val timeTo: String?,
    val deliveredAt: String?,
    val deliveryType: String?,
    val updatedAt: String?,
)

@Entity(tableName = "pending_deliveries")
data class PendingDeliveryEntity(
    @PrimaryKey val clientId: String,
    val campaignId: Int,
    val beneficiaryId: Int,
    val beneficiaryName: String,
    val displayCode: String,
    val queuedAt: Long,
    val syncStatus: String = "pending",
)

@Entity(tableName = "recent_delivered_cache")
data class RecentDeliveredEntity(
    @PrimaryKey(autoGenerate = true) val rowId: Long = 0,
    val campaignId: Int,
    val displayCode: String,
    val name: String,
    val deliveredAt: String?,
    val deliveryType: String?,
    val sortOrder: Int = 0,
)

@Entity(tableName = "late_cache")
data class LateEntity(
    @PrimaryKey val beneficiaryId: Int,
    val campaignId: Int,
    val displayCode: String,
    val name: String,
    val deliveryDate: String?,
    val windowNum: Int?,
)
