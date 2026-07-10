package com.rec.deliverylists.data.remote

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path

interface DeliveryApi {
    @GET("/api/mobile/health")
    suspend fun health(): HealthResponse

    @POST("/api/mobile/login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @GET("/api/mobile/campaigns")
    suspend fun campaigns(@Header("Authorization") auth: String): CampaignsResponse

    @GET("/api/mobile/campaigns/{id}/snapshot")
    suspend fun snapshot(
        @Header("Authorization") auth: String,
        @Path("id") campaignId: Int,
    ): SnapshotResponse

    @POST("/api/mobile/sync")
    suspend fun sync(
        @Header("Authorization") auth: String,
        @Body body: SyncRequest,
    ): SyncResponse
}
