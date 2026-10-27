package com.juwri.owner.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/** Models for the server contract in IMPLEMENTATION-SPEC §4 (unknown keys ignored). */
val ApiJson = Json { ignoreUnknownKeys = true; explicitNulls = false; coerceInputValues = true }

@Serializable
data class PlatformVersion(
    @SerialName("min_version") val minVersion: String = "",
    @SerialName("latest_version") val latestVersion: String = "",
    @SerialName("store_url") val storeUrl: String = "",
    @SerialName("update_required") val updateRequired: Boolean = false,
    @SerialName("update_available") val updateAvailable: Boolean = false,
)

@Serializable
data class AppLinks(
    val login: String? = null,
    val register: String? = null,
    @SerialName("forgot_password") val forgotPassword: String? = null,
)

@Serializable
data class ConfigResponse(
    val enabled: Boolean = true,
    val versions: Map<String, PlatformVersion> = emptyMap(),
    val links: AppLinks = AppLinks(),
)

@Serializable
data class OwnerUser(
    val id: Int,
    val name: String = "",
    val email: String? = null,
    val username: String? = null,
    val locale: String? = null,
    val dir: String = "ltr",
)

@Serializable
data class Store(
    val id: Int,
    val title: String = "",
    val slug: String? = null,
    @SerialName("store_type") val storeType: String? = null,
    val group: String? = null,
    @SerialName("logo_url") val logoUrl: String? = null,
    val currency: String? = null,
)

@Serializable
data class LoginResponse(
    val token: String,
    val user: OwnerUser,
    val stores: List<Store> = emptyList(),
    @SerialName("default_store_id") val defaultStoreId: Int? = null,
)

@Serializable
data class Tab(
    val key: String,
    val label: String,
    val icon: String = "",
    val path: String? = null,
)

@Serializable
data class CurrentStore(
    val id: Int,
    @SerialName("push_enabled") val pushEnabled: Boolean = false,
)

@Serializable
data class MeResponse(
    val user: OwnerUser,
    val stores: List<Store> = emptyList(),
    @SerialName("default_store_id") val defaultStoreId: Int? = null,
    val store: CurrentStore? = null,
    val tabs: List<Tab> = emptyList(),
    val more: List<Tab> = emptyList(),
    @SerialName("onboarding_path") val onboardingPath: String? = null,
)

@Serializable
data class SessionResponse(val url: String, @SerialName("expires_in") val expiresIn: Int = 60)

@Serializable
data class ApiErrorBody(val message: String? = null, val code: String? = null)

class ApiException(val status: Int, val code: String?, override val message: String) : Exception(message) {
    val isUnauthorized get() = status == 401 && code != "two_factor_required"
    val needsTwoFactor get() = code == "two_factor_required"
}
