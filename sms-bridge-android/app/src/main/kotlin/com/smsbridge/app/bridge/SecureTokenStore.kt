package com.smsbridge.app.bridge

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/** Stores the worker bearer token encrypted by an Android Keystore key that never leaves the device. */
class SecureTokenStore(context: Context) {
    private val preferences = context.getSharedPreferences("sms_bridge_secure", Context.MODE_PRIVATE)

    fun read(): String = runCatching {
        val stored = preferences.getString(TOKEN_KEY, null) ?: return ""
        val bytes = Base64.decode(stored, Base64.NO_WRAP)
        val cipher = Cipher.getInstance(TRANSFORMATION).apply {
            init(Cipher.DECRYPT_MODE, key(), javax.crypto.spec.GCMParameterSpec(TAG_BITS, bytes.copyOfRange(0, IV_BYTES)))
        }
        cipher.doFinal(bytes.copyOfRange(IV_BYTES, bytes.size)).toString(Charsets.UTF_8)
    }.getOrDefault("")

    fun write(token: String) {
        if (token.isBlank()) {
            preferences.edit().remove(TOKEN_KEY).apply()
            return
        }
        val cipher = Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.ENCRYPT_MODE, key()) }
        val encrypted = cipher.iv + cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        preferences.edit().putString(TOKEN_KEY, Base64.encodeToString(encrypted, Base64.NO_WRAP)).apply()
    }

    private fun key(): SecretKey {
        val store = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        (store.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE).apply {
            init(
                KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .build(),
            )
        }.generateKey()
    }

    private companion object {
        const val KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "sms_bridge_worker_token"
        const val TOKEN_KEY = "encrypted_token"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val IV_BYTES = 12
        const val TAG_BITS = 128
    }
}
