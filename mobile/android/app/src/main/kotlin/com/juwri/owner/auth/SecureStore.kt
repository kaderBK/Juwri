package com.juwri.owner.auth

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.io.File
import java.security.KeyStore
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * The app token lives ONLY here: AES-256-GCM with a non-exportable key in the
 * Android Keystore, ciphertext in a private no-backup file. It never touches
 * SharedPreferences, logs or URLs (AGENT-KICKOFF rule 3). Biometric unlock
 * gates reading it at launch (LauncherActivity).
 *
 * Non-secret install state (device id, selected store, last registered push
 * token) lives in plain preferences.
 */
class SecureStore(context: Context) {
    private val app = context.applicationContext
    private val tokenFile = File(app.noBackupFilesDir, "owner_app_token.bin")
    private val prefs = app.getSharedPreferences("owner_app_state", Context.MODE_PRIVATE)

    // ── token (secret) ───────────────────────────────────────────────────────

    fun hasToken(): Boolean = tokenFile.exists()

    fun readToken(): String? = try {
        if (!tokenFile.exists()) null else {
            val raw = tokenFile.readBytes()
            val iv = raw.copyOfRange(0, IV_LEN)
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, iv))
            String(cipher.doFinal(raw.copyOfRange(IV_LEN, raw.size)), Charsets.UTF_8)
        }
    } catch (e: Exception) {
        // Key invalidated (e.g. lock screen removed) or file corrupt → treat as logged out.
        clearToken()
        null
    }

    fun writeToken(token: String) {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val iv = cipher.iv
        check(iv.size == IV_LEN)
        tokenFile.writeBytes(iv + cipher.doFinal(token.toByteArray(Charsets.UTF_8)))
    }

    fun clearToken() {
        tokenFile.delete()
        try {
            KeyStore.getInstance(KEYSTORE).apply { load(null) }.deleteEntry(KEY_ALIAS)
        } catch (_: Exception) {
        }
    }

    // ── install state (not secret) ───────────────────────────────────────────

    /** Stable per-install UUID (spec §3.2 step 4). */
    val deviceId: String
        get() = prefs.getString("device_id", null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString("device_id", it).apply()
        }

    var storeId: Int?
        get() = prefs.getInt("store_id", 0).takeIf { it > 0 }
        set(value) = prefs.edit().putInt("store_id", value ?: 0).apply()

    /** FCM token last sent to POST /devices (a device address, not a credential). */
    var registeredPushToken: String?
        get() = prefs.getString("push_token", null)
        set(value) = prefs.edit().putString("push_token", value).apply()

    var pushPromptShown: Boolean
        get() = prefs.getBoolean("push_prompt_shown", false)
        set(value) = prefs.edit().putBoolean("push_prompt_shown", value).apply()

    fun wipe() {
        clearToken()
        prefs.edit().remove("store_id").remove("push_token").apply()
    }

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        (ks.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
        gen.init(
            KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return gen.generateKey()
    }

    companion object {
        private const val KEYSTORE = "AndroidKeyStore"
        private const val KEY_ALIAS = "juwri_owner_app_token"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val IV_LEN = 12

        @Volatile private var instance: SecureStore? = null
        fun get(context: Context): SecureStore =
            instance ?: synchronized(this) { instance ?: SecureStore(context).also { instance = it } }
    }
}
