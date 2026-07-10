package com.rec.deliverylists.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import com.rec.deliverylists.data.DeliveryRepository
import com.rec.deliverylists.data.local.BeneficiaryEntity
import com.rec.deliverylists.data.local.CampaignEntity
import kotlinx.coroutines.launch

@Composable
fun CampaignDashboardScreen(
    campaignId: Int,
    onBack: () -> Unit,
) {
    val repo = DeliveryApp.repository
    var campaign by remember { mutableStateOf<CampaignEntity?>(null) }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<BeneficiaryEntity>>(emptyList()) }
    var selected by remember { mutableStateOf<BeneficiaryEntity?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    val recent by repo.observeRecent(campaignId).collectAsState(initial = emptyList())
    val late by repo.observeLate(campaignId).collectAsState(initial = emptyList())
    val scope = rememberCoroutineScope()

    LaunchedEffect(campaignId) {
        campaign = repo.getCampaign(campaignId)
        repo.syncCampaign(campaignId)
        campaign = repo.getCampaign(campaignId)
    }

    val c = campaign
    if (c == null) {
        Text("جاري التحميل...")
        return
    }

    Column(
        Modifier.fillMaxSize().padding(16.dp).verticalScroll(rememberScrollState()),
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            OutlinedButton(onClick = onBack) { Text("رجوع") }
            OutlinedButton(onClick = {
                scope.launch {
                    repo.syncCampaign(campaignId)
                        .onSuccess { message = "تمت المزامنة"; campaign = repo.getCampaign(campaignId) }
                        .onFailure { message = it.message }
                }
            }) { Text("مزامنة") }
        }
        Text(c.name, style = MaterialTheme.typography.headlineSmall)
        if (!c.campaignActive) {
            Text("تم إنهاء التسليم", color = MaterialTheme.colorScheme.error)
        }
        Spacer(Modifier.height(8.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
            StatBox("الرصيد", c.balance.toString())
            StatBox("مُسلَّم", c.delivered.toString())
            StatBox("افتتاحي", c.openingQuantity.toString())
            StatBox("متبقي", c.pending.toString())
        }
        message?.let { Text(it, Modifier.padding(vertical = 4.dp)) }
        Spacer(Modifier.height(12.dp))

        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            label = { Text("بحث: رقم تسلسلي / هوية / اسم") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Button(
            onClick = {
                scope.launch {
                    results = repo.search(campaignId, query)
                    selected = results.firstOrNull()
                    if (results.isEmpty()) message = "لم يُعثر على مستفيد"
                }
            },
            modifier = Modifier.fillMaxWidth(),
            enabled = c.campaignActive,
        ) { Text("بحث") }

        selected?.let { b ->
            Spacer(Modifier.height(8.dp))
            BeneficiaryCard(b) {
                scope.launch {
                    repo.confirmDelivery(campaignId, b)
                        .onSuccess {
                            message = "تم تسجيل التسليم"
                            campaign = repo.getCampaign(campaignId)
                            selected = null
                            query = ""
                        }
                        .onFailure { message = it.message }
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        Text("متأخرون (${late.size})", style = MaterialTheme.typography.titleMedium)
        late.take(10).forEach { row ->
            Text("${row.displayCode} — ${row.name} — ${row.deliveryDate ?: ""}")
        }
        Spacer(Modifier.height(12.dp))
        Text("آخر المستلمين", style = MaterialTheme.typography.titleMedium)
        recent.take(15).forEach { row ->
            Text("${row.displayCode} — ${row.name} — ${row.deliveredAt ?: ""}")
        }
    }
}

@Composable
private fun StatBox(label: String, value: String) {
    Column(horizontalAlignment = androidx.compose.ui.Alignment.CenterHorizontally) {
        Text(value, style = MaterialTheme.typography.titleLarge)
        Text(label, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun BeneficiaryCard(b: BeneficiaryEntity, onConfirm: () -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp)) {
            Text(b.name, style = MaterialTheme.typography.titleMedium)
            Text("الكود: ${b.displayCode}")
            Text("الهوية: ${b.nationalId}")
            Text("الموعد: ${b.deliveryDate ?: "—"} — شباك ${b.windowNum ?: "—"}")
            Text("الحالة: ${b.receiptStatus}")
            if (b.receiptStatus != DeliveryRepository.STATUS_DELIVERED) {
                Spacer(Modifier.height(8.dp))
                Button(onClick = onConfirm, modifier = Modifier.fillMaxWidth()) {
                    Text("تأكيد التسليم")
                }
            }
        }
    }
}
