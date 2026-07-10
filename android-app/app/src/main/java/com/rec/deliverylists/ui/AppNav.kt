package com.rec.deliverylists.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import com.rec.deliverylists.DeliveryApp

@Composable
fun AppNav() {
    val repo = DeliveryApp.repository
    val token by repo.getTokenFlow().collectAsState(initial = null)
    var openCampaignId by remember { mutableStateOf<Int?>(null) }

    when {
        token.isNullOrBlank() -> LoginScreen(onLoggedIn = { /* flow updates */ })
        openCampaignId != null -> CampaignDashboardScreen(
            campaignId = openCampaignId!!,
            onBack = { openCampaignId = null },
        )
        else -> CampaignListScreen(
            onOpenCampaign = { openCampaignId = it },
            onLogout = { /* flow clears on logout */ },
        )
    }
}
