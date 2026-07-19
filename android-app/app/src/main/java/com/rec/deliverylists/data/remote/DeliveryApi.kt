package com.rec.deliverylists.data.remote

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

interface DeliveryApi {
    @GET("/api/mobile/health")
    suspend fun health(): HealthResponse

    @POST("/api/mobile/login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @GET("/api/mobile/campaigns")
    suspend fun campaigns(): CampaignsResponse

    @GET("/api/mobile/campaigns/{id}/snapshot")
    suspend fun snapshot(
        @Path("id") campaignId: Int,
    ): SnapshotResponse

    @POST("/api/mobile/sync")
    suspend fun sync(
        @Body body: SyncRequest,
    ): SyncResponse

    @POST("/api/mobile/deliver")
    suspend fun deliver(
        @Body body: DeliverRequest,
    ): DeliverResponse
}
