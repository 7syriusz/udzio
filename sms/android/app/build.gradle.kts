plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.kapt")
}
android {
    namespace = "pl.udzio.smslab"
    compileSdk = 36
    defaultConfig {
        applicationId = "pl.udzio.smslab"
        minSdk = 33
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0-lab"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    signingConfigs {
        create("labRelease") {
            val keyPath = System.getenv("LAB_KEYSTORE")
            if (keyPath != null) {
                storeFile = file(keyPath)
                storePassword = System.getenv("LAB_STORE_PASSWORD")
                keyAlias = System.getenv("LAB_KEY_ALIAS") ?: "smslab"
                keyPassword = System.getenv("LAB_KEY_PASSWORD")
            }
        }
    }
    buildTypes { getByName("release") { signingConfig = signingConfigs.getByName("labRelease"); isMinifyEnabled = false } }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures { buildConfig = true }
}
dependencies {
    implementation("androidx.room:room-runtime:2.8.4")
    kapt("androidx.room:room-compiler:2.8.4")
    implementation("androidx.work:work-runtime-ktx:2.11.2")
    testImplementation("junit:junit:4.13.2")
}
