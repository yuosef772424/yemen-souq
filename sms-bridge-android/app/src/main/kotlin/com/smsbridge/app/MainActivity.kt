package com.smsbridge.app

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.smsbridge.app.bridge.BridgeConfig
import com.smsbridge.app.bridge.BridgeService
import com.smsbridge.app.bridge.BridgeStore
import com.smsbridge.app.sms.InboundFilter
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    BridgeSetup()
                }
            }
        }
    }
}

@androidx.compose.runtime.Composable
private fun BridgeSetup() {
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    val store = remember { BridgeStore(context.applicationContext) }
    var config by remember { mutableStateOf(BridgeConfig()) }
    var allowedNumbersText by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("Loading saved configuration…") }
    val permissions = buildList {
        add(Manifest.permission.SEND_SMS)
        add(Manifest.permission.RECEIVE_SMS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
    }.toTypedArray()
    val permissionsLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { results ->
        status = if (results.filterKeys { it != Manifest.permission.POST_NOTIFICATIONS }.values.all { it }) {
            "SMS permissions granted."
        } else {
            "Sending and receiving cannot work until SMS permissions are granted."
        }
    }

    LaunchedEffect(Unit) {
        config = store.config()
        allowedNumbersText = config.allowedNumbers.joinToString(", ")
        status = if (config.enabled) "Saved configuration is enabled. Start the bridge to connect." else "Configure the bridge, then start it."
    }

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("SMS Bridge", style = MaterialTheme.typography.headlineMedium)
        Text("A phone-initiated bridge: the phone pulls its own queue, sends through the default SIM, and uploads only locally accepted incoming SMS messages.")

        Card(modifier = Modifier.fillMaxWidth()) {
            Text(
                "Privacy: an incoming SMS is never sent to the server unless its exact sender is listed below or its body starts with the configured code. Leaving both filters empty denies every incoming message.",
                modifier = Modifier.padding(14.dp),
            )
        }

        OutlinedTextField(
            value = config.serverUrl,
            onValueChange = { config = config.copy(serverUrl = it.trim()) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            label = { Text("Server URL (for example https://example.com)") },
        )
        OutlinedTextField(
            value = config.apiToken,
            onValueChange = { config = config.copy(apiToken = it.trim()) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            label = { Text("Bridge worker token") },
        )
        OutlinedTextField(
            value = allowedNumbersText,
            onValueChange = { allowedNumbersText = it },
            modifier = Modifier.fillMaxWidth(),
            label = { Text("Allowed sender numbers (comma-separated, optional)") },
        )
        OutlinedTextField(
            value = config.requiredPrefix,
            onValueChange = { config = config.copy(requiredPrefix = it) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            label = { Text("Required starting code (optional, e.g. SQ-)") },
        )
        OutlinedTextField(
            value = config.pollIntervalSeconds.toString(),
            onValueChange = { value -> config = config.copy(pollIntervalSeconds = value.toIntOrNull()?.coerceIn(5, 300) ?: 30) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            label = { Text("Poll interval in seconds (5–300)") },
        )

        Button(onClick = { permissionsLauncher.launch(permissions) }, modifier = Modifier.fillMaxWidth()) {
            Text("Grant SMS permissions")
        }
        Button(
            onClick = {
                scope.launch {
                    val saved = config.copy(allowedNumbers = InboundFilter.parseNumbers(allowedNumbersText), enabled = true).withWorkerId()
                    store.saveConfig(saved)
                    config = saved
                    if (!saved.isReady()) {
                        status = "Use an HTTPS server URL and enter the worker token first."
                        return@launch
                    }
                    val smsGranted = ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED &&
                        ContextCompat.checkSelfPermission(context, Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED
                    if (!smsGranted) {
                        status = "Configuration was saved, but SMS permissions are still missing."
                        return@launch
                    }
                    BridgeService.start(context)
                    status = "Bridge started. Keep its notification visible; it is the proof that Android allows the worker to run."
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) { Text("Save and start bridge") }
        TextButton(
            onClick = {
                scope.launch {
                    val saved = config.copy(allowedNumbers = InboundFilter.parseNumbers(allowedNumbersText), enabled = false).withWorkerId()
                    store.saveConfig(saved)
                    config = saved
                    BridgeService.stop(context)
                    status = "Bridge stopped."
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) { Text("Stop bridge") }
        TextButton(
            onClick = { context.startActivity(android.content.Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) },
            modifier = Modifier.fillMaxWidth(),
        ) { Text("Open battery settings") }

        Card(modifier = Modifier.fillMaxWidth()) {
            Text(status, modifier = Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium)
        }
        Text(
            "Distribution note: Google Play limits SMS permissions to eligible core uses, commonly a default SMS handler. This build is for a managed/private deployment unless its Play policy eligibility and disclosure are completed.",
            style = MaterialTheme.typography.bodySmall,
        )
    }
}
