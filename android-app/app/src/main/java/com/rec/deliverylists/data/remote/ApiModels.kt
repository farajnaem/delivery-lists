package com.rec.deliverylists.data.remote

data class LoginRequest(val email: String, val password: String)

data class UserDto(
    val id: Int,
    val name: String,
    val email: String,
    val role: String,
)

data class LoginResponse(
    val ok: Boolean,
    val token: String? = null,
    val user: UserDto? = null,
    val error: String? = null,
)

data class CampaignDto(
    val id: Int,
    val name: String,
    val parcel_name: String,
    val warehouse_name: String,
    val delivery_start: String,
    val delivery_end: String,
    val delivery_closed_at: String? = null,
    val campaign_active: Boolean = true,
    val beneficiary_count: Int = 0,
    val delivered_count: Int = 0,
    val sync_token: String? = null,
    val stock: StockDto? = null,
)

data class StockDto(
    val opening_quantity: Int = 0,
    val delivered: Int = 0,
    val balance: Int = 0,
    val pending: Int = 0,
    val on_time: Int = 0,
    val late: Int = 0,
    val today_delivered: Int = 0,
    val planned_today: Int = 0,
    val campaign_active: Boolean = true,
    val total_beneficiaries: Int = 0,
)

data class BeneficiaryDto(
    val id: Int,
    val campaign_id: Int,
    val name: String,
    val national_id: String,
    val mobile: String = "",
    val receipt_status: String,
    val disbursement_code: String? = null,
    val display_code: String? = null,
    val sort_order: Int = 0,
    val delivery_date: String? = null,
    val window_num: Int? = null,
    val time_from: String? = null,
    val time_to: String? = null,
    val delivered_at: String? = null,
    val delivery_type: String? = null,
    val actual_delivery_date: String? = null,
    val delivered_by_name: String? = null,
    val updated_at: String? = null,
)

data class RecentDeliveredDto(
    val id: Int? = null,
    val name: String,
    val disbursement_code: String? = null,
    val display_code: String? = null,
    val sort_order: Int? = null,
    val national_id: String? = null,
    val delivery_type: String? = null,
    val delivered_at: String? = null,
    val delivery_date: String? = null,
    val window_num: Int? = null,
    val delivered_by_name: String? = null,
)

data class CampaignsResponse(val ok: Boolean, val campaigns: List<CampaignDto> = emptyList(), val hint: String? = null, val error: String? = null)

data class SnapshotResponse(
    val ok: Boolean = true,
    val campaign: CampaignDto? = null,
    val sync_token: String? = null,
    val beneficiaries: List<BeneficiaryDto> = emptyList(),
    val stock: StockDto? = null,
    val recent_delivered: List<RecentDeliveredDto> = emptyList(),
    val late: List<RecentDeliveredDto> = emptyList(),
    val error: String? = null,
)

data class PendingDeliveryItem(
    val beneficiary_id: Int,
    val client_id: String,
)

data class SyncRequest(
    val campaign_id: Int,
    val last_sync_token: String? = null,
    val pending_deliveries: List<PendingDeliveryItem> = emptyList(),
)

data class SyncUploadResult(
    val ok: Boolean = true,
    val results: List<Map<String, Any?>> = emptyList(),
    val synced: Int = 0,
    val failed: Int = 0,
)

data class SyncResponse(
    val ok: Boolean = true,
    val upload: SyncUploadResult? = null,
    val sync_token: String? = null,
    val updated_beneficiaries: List<BeneficiaryDto> = emptyList(),
    val campaign: CampaignDto? = null,
    val stock: StockDto? = null,
    val recent_delivered: List<RecentDeliveredDto> = emptyList(),
    val late: List<RecentDeliveredDto> = emptyList(),
    val error: String? = null,
)

data class HealthResponse(val ok: Boolean, val service: String? = null)
