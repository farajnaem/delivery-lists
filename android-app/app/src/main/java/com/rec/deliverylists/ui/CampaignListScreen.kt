package com.rec.deliverylists.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.rec.deliverylists.DeliveryApp
import com.rec.deliverylists.data.local.CampaignEntity
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
    var downloadingId by remember { mutableStateOf<Int?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        loading = true
        repo.refreshCampaignList()
        repo.syncAllPending()
        loading = false
    }

    Column(Modifier.fillMaxSize().padding(16.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("العمليات", style = MaterialTheme.typography.headlineSmall)
            OutlinedButton(onClick = {
                scope.launch {
                    repo.logout()
                    onLogout()
                }
            }) { Text("خروج") }
        }
        if (pendingCount > 0) {
            Text("بانتظار المزامنة: $pendingCount", color = MaterialTheme.colorScheme.primary)
        }
        message?.let { Text(it) }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = {
                scope.launch {
                    loading = true
                    repo.refreshCampaignList().onFailure { message = it.message }
                    loading = false
                }
            }) { Text("تحديث القائمة") }
            OutlinedButton(onClick = {
                scope.launch {
                    repo.syncAllPending()
                        .onSuccess { message = "تمت مزامنة $it تسليم" }
                        .onFailure { message = it.message }
                }
            }) { Text("مزامنة الآن") }
        }
        Spacer(Modifier.height(12.dp))
        if (loading) CircularProgressIndicator()
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(campaigns, key = { it.id }) { c ->
                CampaignCard(
                    campaign = c,
                    downloading = downloadingId == c.id,
                    onDownload = {
                        downloadingId = c.id
                        scope.launch {
                            repo.downloadSnapshot(c.id)
                                .onSuccess { message = "تم تحميل ${c.name}" }
                                .onFailure { message = it.message }
                            downloadingId = null
                        }
                    },
                    onOpen = { onOpenCampaign(c.id) },
                )
            }
        }
    }
}

@Composable
private fun CampaignCard(
    campaign: CampaignEntity,
    downloading: Boolean,
    onDownload: () -> Unit,
    onOpen: () -> Unit,
) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp)) {
            Text(campaign.name, style = MaterialTheme.typography.titleMedium)
            Text("${campaign.parcelName} — ${campaign.warehouseName}")
            Text("مُسلَّم: ${campaign.delivered} | الرصيد: ${campaign.balance}")
            if (campaign.snapshotComplete) {
                Text("محمّل محلياً ✓", color = MaterialTheme.colorScheme.primary)
            } else {
                Text("يجب التحميل قبل التسليم", color = MaterialTheme.colorScheme.error)
            }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = onDownload, enabled = !downloading) {
                    if (downloading) CircularProgressIndicator() else Text("تحميل/تحديث")
                }
                Button(
                    onClick = onOpen,
                    enabled = campaign.snapshotComplete,
                ) { Text("فتح التسليم") }
            }
        }
    }
}
