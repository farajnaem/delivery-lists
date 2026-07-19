package com.rec.deliverylists.util

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.rec.deliverylists.worker.SyncWorker
import java.util.concurrent.TimeUnit

object SyncScheduler {
    private const val WORK_NAME = "delivery_sync"

    fun schedule(context: Context) {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
        val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(constraints)
            .build()
        val wm = WorkManager.getInstance(context)
        wm.enqueueUniquePeriodicWork(
            WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
        // مزامنة فورية عند فتح التطبيق (بالإضافة للدورية)
        wm.enqueue(
            androidx.work.OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(constraints)
                .build(),
        )
    }
}
