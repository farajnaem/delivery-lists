package com.rec.deliverylists.ui

import androidx.compose.foundation.clickable
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
import androidx.compose.ui.Alignment
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
    var message by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        loading = true
        repo.refreshCampaignList()
            .onFailure { message = it.message ?: "فشل تحديث القائمة" }
        repo.syncAllPending()
        loading = false
    }

    Column(Modifier.fillMaxSize().padding(16.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("اختر الطرد", style = MaterialTheme.typography.headlineSmall)
            OutlinedButton(onClick = {
                scope.launch {
                    repo.logout()
                    onLogout()
                }
            }) { Text("خروج") }
        }
        Text(
            "اضغط على الطرد لفتح الاستلام والاستعلام",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
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
        if (loading) {
            CircularProgressIndicator(Modifier.align(Alignment.CenterHorizontally))
        }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(campaigns, key = { it.id }) { c ->
                ParcelCard(campaign = c, onClick = { onOpenCampaign(c.id) })
            }
        }
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
    ) {
        Column(Modifier.padding(12.dp)) {
            Text(
                "الطرد",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
            Text(campaign.parcelName, style = MaterialTheme.typography.titleLarge)
            Text(campaign.name, style = MaterialTheme.typography.titleMedium)
            Text(
                "${campaign.warehouseName} · ${campaign.deliveryStart} — ${campaign.deliveryEnd}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(6.dp))
            Text("الرصيد: ${campaign.balance} · مُسلَّم: ${campaign.delivered}")
            if (!campaign.campaignActive) {
                Text("التسليم مُنهى", color = MaterialTheme.colorScheme.error)
            } else {
                Text(
                    "استلام واستعلام ←",
                    color = MaterialTheme.colorScheme.primary,
                    style = MaterialTheme.typography.labelLarge,
                )
            }
        }
    }
}
