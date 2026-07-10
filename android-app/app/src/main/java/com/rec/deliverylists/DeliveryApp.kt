package com.rec.deliverylists

import android.app.Application
import androidx.room.Room
import com.rec.deliverylists.data.DeliveryRepository
import com.rec.deliverylists.data.SessionStore
import com.rec.deliverylists.data.local.AppDatabase
import com.rec.deliverylists.util.SyncScheduler
import kotlinx.coroutines.runBlocking

class DeliveryApp : Application() {
    lateinit var repository: DeliveryRepository
        private set

    override fun onCreate() {
        super.onCreate()
        instance = this
        val db = Room.databaseBuilder(this, AppDatabase::class.java, "delivery_lists.db")
            .fallbackToDestructiveMigration()
            .build()
        val session = SessionStore(this)
        runBlocking { session.restoreCachedToken() }
        repository = DeliveryRepository(db, session)
        SyncScheduler.schedule(this)
    }

    companion object {
        lateinit var instance: DeliveryApp
            private set

        val repository: DeliveryRepository
            get() = instance.repository
    }
}
