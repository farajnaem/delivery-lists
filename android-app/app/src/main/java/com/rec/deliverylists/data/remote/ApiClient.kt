package com.rec.deliverylists.data.remote

import com.rec.deliverylists.BuildConfig
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {
    private val logging = HttpLoggingInterceptor().apply {
        level = if (BuildConfig.DEBUG) {
            HttpLoggingInterceptor.Level.BASIC
        } else {
            HttpLoggingInterceptor.Level.NONE
        }
    }

    private val http = OkHttpClient.Builder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(120, TimeUnit.SECONDS)
        .writeTimeout(120, TimeUnit.SECONDS)
        .addInterceptor(logging)
        .build()

    val api: DeliveryApi by lazy {
        Retrofit.Builder()
            .baseUrl(BuildConfig.SERVER_URL.trimEnd('/') + "/")
            .client(http)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(DeliveryApi::class.java)
    }

    fun bearer(token: String): String = "Bearer $token"
}
