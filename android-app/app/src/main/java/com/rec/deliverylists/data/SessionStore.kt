package com.rec.deliverylists.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore("session")

class SessionStore(private val context: Context) {
    private val tokenKey = stringPreferencesKey("token")
    private val userNameKey = stringPreferencesKey("user_name")
    private val userEmailKey = stringPreferencesKey("user_email")

    val tokenFlow: Flow<String?> = context.dataStore.data.map { it[tokenKey] }
    val userNameFlow: Flow<String?> = context.dataStore.data.map { it[userNameKey] }

    suspend fun save(token: String, name: String, email: String) {
        context.dataStore.edit {
            it[tokenKey] = token
            it[userNameKey] = name
            it[userEmailKey] = email
        }
    }

    suspend fun clear() {
        context.dataStore.edit { it.clear() }
    }
}
