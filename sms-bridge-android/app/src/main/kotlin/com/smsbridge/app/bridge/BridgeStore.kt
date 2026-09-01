package com.smsbridge.app.bridge

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

private val Context.bridgeDataStore by preferencesDataStore(name = "sms_bridge")

/** Stores only bridge configuration and messages that already passed the local privacy filter. */
class BridgeStore(private val context: Context) {
    private val mutex = Mutex()
    private val configKey = stringPreferencesKey("config")
    private val inboxKey = stringPreferencesKey("inbound_queue")
    private val json = Json { ignoreUnknownKeys = true }
    private val tokenStore = SecureTokenStore(context)

    suspend fun config(): BridgeConfig = mutex.withLock {
        val raw = context.bridgeDataStore.data.first()[configKey] ?: return@withLock BridgeConfig()
        runCatching { json.decodeFromString<BridgeConfig>(raw) }.getOrDefault(BridgeConfig()).copy(apiToken = tokenStore.read())
    }

    suspend fun saveConfig(config: BridgeConfig) = mutex.withLock {
        tokenStore.write(config.apiToken)
        context.bridgeDataStore.edit { it[configKey] = json.encodeToString(config.withWorkerId()) }
    }

    suspend fun queuedInbound(): List<InboundMessage> = mutex.withLock { readInbox() }

    suspend fun enqueueInbound(message: InboundMessage) = mutex.withLock {
        val messages = readInbox()
        if (messages.none { it.id == message.id }) {
            context.bridgeDataStore.edit { it[inboxKey] = json.encodeToString(messages + message) }
        }
    }

    suspend fun removeInbound(ids: Set<String>) = mutex.withLock {
        context.bridgeDataStore.edit { it[inboxKey] = json.encodeToString(readInbox().filterNot { item -> item.id in ids }) }
    }

    private suspend fun readInbox(): List<InboundMessage> {
        val raw = context.bridgeDataStore.data.first()[inboxKey] ?: return emptyList()
        return runCatching { json.decodeFromString<List<InboundMessage>>(raw) }.getOrDefault(emptyList())
    }
}
