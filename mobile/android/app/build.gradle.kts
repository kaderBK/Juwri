import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.serialization")
    id("org.jetbrains.kotlin.plugin.parcelize")
}

// ── Build-time config ────────────────────────────────────────────────────────
// Base URL: -Pjuwri.baseUrl=… (gradle.properties defaults to the dev site).
val baseUrl = (findProperty("juwri.baseUrl") as String? ?: "https://menutempl.test").trimEnd('/')
val appVersionName = "1.0.0"
val appVersionCode = 1

// Firebase config lives OUTSIDE the repo (~/juwri-mobile-config). Copy it in at
// configuration time; the destination is gitignored (mobile/.gitignore).
val firebaseSource = File(System.getProperty("user.home"), "juwri-mobile-config/google-services.json")
val firebaseTarget = file("google-services.json")
if (!firebaseTarget.exists() && firebaseSource.exists()) {
    firebaseSource.copyTo(firebaseTarget)
}
if (firebaseTarget.exists()) {
    apply(plugin = "com.google.gms.google-services")
} else {
    logger.warn("google-services.json missing — building without Firebase (push disabled).")
}

// DEBUG ONLY: trust the local Herd/Valet CA so the emulator can open https://*.test.
// Generated into build/ (never in src/, never in release).
val devCaSource = File(System.getProperty("user.home"), "Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem")
val devResDir = layout.buildDirectory.dir("generated/devca/res").get().asFile
val generateDevNetworkConfig by tasks.registering {
    outputs.dir(devResDir)
    doLast {
        val xml = File(devResDir, "xml").apply { mkdirs() }
        val raw = File(devResDir, "raw").apply { mkdirs() }
        val hasCa = devCaSource.exists()
        if (hasCa) devCaSource.copyTo(File(raw, "dev_ca.pem"), overwrite = true)
        File(xml, "network_security_config.xml").writeText(
            """
            <?xml version="1.0" encoding="utf-8"?>
            <!-- GENERATED for DEBUG builds only (app/build.gradle.kts). -->
            <network-security-config>
                <base-config cleartextTrafficPermitted="false">
                    <trust-anchors>
                        <certificates src="system" />
                        ${if (hasCa) "<certificates src=\"@raw/dev_ca\" />" else ""}
                    </trust-anchors>
                </base-config>
            </network-security-config>
            """.trimIndent()
        )
    }
}

android {
    namespace = "com.juwri.owner"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.juwri.owner"
        minSdk = 28
        targetSdk = 35
        versionCode = appVersionCode
        versionName = appVersionName

        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")
        manifestPlaceholders["appLinkHost"] = baseUrl.removePrefix("https://").removePrefix("http://").substringBefore('/')
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Signing is configured by the owner outside the repo (keystore never committed).
        }
        debug {
            isDebuggable = true
        }
    }

    sourceSets {
        named("main") { java.srcDirs("src/main/kotlin") }
        named("debug") { res.srcDirs(devResDir) }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

tasks.matching { it.name == "preDebugBuild" || it.name.startsWith("mergeDebugResources") || it.name.startsWith("generateDebugResValues") || it.name.startsWith("packageDebugResources") }
    .configureEach { dependsOn(generateDevNetworkConfig) }

kotlin {
    compilerOptions { jvmTarget.set(JvmTarget.JVM_17) }
}

dependencies {
    // Hotwire Native (pinned, verified latest 1.x on 2026-09-25)
    implementation("dev.hotwire:core:1.3.1")
    implementation("dev.hotwire:navigation-fragments:1.3.1")

    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.core:core-ktx:1.16.0")
    implementation("androidx.activity:activity-ktx:1.10.1")
    implementation("androidx.fragment:fragment-ktx:1.8.6")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.browser:browser:1.8.0")
    implementation("androidx.biometric:biometric:1.1.0")

    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.8.1")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.1")
    implementation("com.squareup.okhttp3:okhttp:5.3.2")

    implementation(platform("com.google.firebase:firebase-bom:34.3.0"))
    implementation("com.google.firebase:firebase-messaging")
}
