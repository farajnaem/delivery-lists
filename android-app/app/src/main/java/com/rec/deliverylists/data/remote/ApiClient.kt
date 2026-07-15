package com.rec.deliverylists.data.remote

import com.rec.deliverylists.BuildConfig
import com.rec.deliverylists.data.SessionStore
import okhttp3.Interceptor
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

    /**
     * لا نرسل Authorization: Bearer — بعض بروكسيات Coolify/Traefik تعترضه.
     * نعتمد X-Mobile-Token + mobile_token في الرابط.
     */
    private val authInterceptor = Interceptor { chain ->
        val token = SessionStore.cachedToken?.trim().orEmpty()
        val request = if (token.isNotEmpty()) {
            val url = chain.request().url.newBuilder()
                .removeAllQueryParameters("mobile_token")
                .removeAllQueryParameters("mt")
                .addQueryParameter("mobile_token", token)
                .build()
            chain.request().newBuilder()
                .url(url)
                .header("X-Mobile-Token", token)
                .header("X-Delivery-Token", token)
                .removeHeader("Authorization")
                .build()
        } else {
            chain.request().newBuilder()
                .removeHeader("Authorization")
                .build()
        }
        chain.proceed(request)
    }

    private val http = OkHttpClient.Builder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(120, TimeUnit.SECONDS)
        .writeTimeout(120, TimeUnit.SECONDS)
        .addInterceptor(authInterceptor)
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
}
