package com.rec.deliverylists.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Snackbar
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.rec.deliverylists.DeliveryApp
import com.rec.deliverylists.data.local.CampaignEntity
import com.rec.deliverylists.util.ArabicFormat
import kotlinx.coroutines.launch

@Composable
fun CampaignListScreen(
    onOpenCampaign: (Int) -> Unit,
    onLogout: () -> Unit,
) {
    val repo = DeliveryApp.repository
    val campaigns by repo.campaignsFlow.collectAsState(initial = emptyList())
    val pendingCount by repo.pendingCountFlow.collectAsState(initial = 0)
    var loading by remember { mutableStateOf(false) }
    var syncing by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    suspend fun showMsg(text: String) {
        snackbar.showSnackbar(text)
    }

    LaunchedEffect(Unit) {
        loading = true
        repo.refreshCampaignList()
            .onSuccess {
                if (campaigns.isEmpty()) {
                    // list may update async via flow
                }
            }
            .onFailure { showMsg(it.message ?: "فشل تحديث قائمة الطرود") }
        repo.syncAllPending()
            .onSuccess { n ->
                if (n > 0) showMsg("تمت مزامنة $n تسليم معلّق")
            }
            .onFailure { /* silent on auto-sync failure — user can retry */ }
        loading = false
    }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize().padding(16.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text("الطرود", style = MaterialTheme.typography.headlineSmall)
                    Text(
                        "اختر طرداً لبدء الاستلام",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                OutlinedButton(onClick = {
                    scope.launch {
                        repo.logout()
                        onLogout()
                    }
                }) { Text("خروج") }
            }

            if (pendingCount > 0) {
                Spacer(Modifier.height(10.dp))
                Surface(
                    color = MaterialTheme.colorScheme.primaryContainer,
                    shape = RoundedCornerShape(10.dp),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(
                        "بانتظار المزامنة: $pendingCount تسليم — اضغط «مزامنة» عند توفر الإنترنت",
                        modifier = Modifier.padding(12.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onPrimaryContainer,
                    )
                }
            }

            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Button(
                    onClick = {
                        scope.launch {
                            loading = true
                            repo.refreshCampaignList()
                                .onSuccess { showMsg("تم تحديث القائمة") }
                                .onFailure { showMsg(it.message ?: "فشل التحديث") }
                            loading = false
                        }
                    },
                    enabled = !loading && !syncing,
                    modifier = Modifier.weight(1f),
                ) {
                    if (loading) {
                        CircularProgressIndicator(
                            Modifier.size(18.dp),
                            strokeWidth = 2.dp,
                            color = MaterialTheme.colorScheme.onPrimary,
                        )
                    } else {
                        Text("تحديث")
                    }
                }
                OutlinedButton(
                    onClick = {
                        scope.launch {
                            syncing = true
                            repo.syncAllPending()
                                .onSuccess { n ->
                                    showMsg(
                                        if (n > 0) "تمت مزامنة $n تسليم بنجاح"
                                        else "لا توجد تسليمات بانتظار المزامنة",
                                    )
                                }
                                .onFailure { showMsg(it.message ?: "فشلت المزامنة") }
                            syncing = false
                        }
                    },
                    enabled = !loading && !syncing,
                    modifier = Modifier.weight(1f),
                ) {
                    if (syncing) {
                        CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                    } else {
                        Text("مزامنة")
                    }
                }
            }

            Spacer(Modifier.height(12.dp))

            if (loading && campaigns.isEmpty()) {
                Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            } else if (campaigns.isEmpty()) {
                Card(
                    Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                ) {
                    Column(Modifier.padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("لا توجد طرود متاحة", style = MaterialTheme.typography.titleMedium)
                        Spacer(Modifier.height(6.dp))
                        Text(
                            "تأكد أن الكشوف مُولَّدة على السيرفر وأن لديك صلاحية التسليم.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(campaigns, key = { it.id }) { c ->
                        ParcelCard(campaign = c, onClick = { onOpenCampaign(c.id) })
                    }
                }
            }
        }

        SnackbarHost(
            hostState = snackbar,
            modifier = Modifier.align(Alignment.BottomCenter).padding(16.dp),
        ) { data -> FeedbackSnackbar(data) }
    }
}

@Composable
private fun ParcelCard(
    campaign: CampaignEntity,
    onClick: () -> Unit,
) {
    Card(
        Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(14.dp),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(
                "الطرد",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
            Text(campaign.parcelName, style = MaterialTheme.typography.titleLarge)
            Text(campaign.name, style = MaterialTheme.typography.bodyMedium)
            Text(
                "${campaign.warehouseName} · ${campaign.deliveryStart} — ${campaign.deliveryEnd}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(8.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("الرصيد: ${campaign.balance}")
                Text("مُسلَّم: ${campaign.delivered}")
            }
            Spacer(Modifier.height(6.dp))
            Text(
                "آخر مزامنة: ${ArabicFormat.formatLastSync(campaign.lastSyncAt)}",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(4.dp))
            if (!campaign.campaignActive) {
                Text("التسليم مُنهى", color = MaterialTheme.colorScheme.error)
            } else {
                Text(
                    if (campaign.snapshotComplete) "جاهز للاستلام ←" else "يتطلب تحميل أول مرة ←",
                    color = MaterialTheme.colorScheme.primary,
                    style = MaterialTheme.typography.labelLarge,
                )
            }
        }
    }
}

@Composable
fun FeedbackSnackbar(data: androidx.compose.material3.SnackbarData) {
    val msg = data.visuals.message
    val isError = listOf("فشل", "تعذّر", "خطأ", "انتهت", "لا يوجد", "غير", "مهلة", "اتصال")
        .any { msg.contains(it) }
    Snackbar(
        snackbarData = data,
        containerColor = if (isError) MaterialTheme.colorScheme.errorContainer
        else MaterialTheme.colorScheme.primaryContainer,
        contentColor = if (isError) MaterialTheme.colorScheme.onErrorContainer
        else MaterialTheme.colorScheme.onPrimaryContainer,
    )
}
