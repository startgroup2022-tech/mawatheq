import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing material is supplied by the owner and never committed:
// android/key.properties points at a keystore (.jks/.keystore, both gitignored).
// When the file is absent — a plain `flutter build apk --release` on a fresh clone
// — the release build falls back to the debug signature so the build still works,
// exactly as the customer app does. A Play upload needs the owner's upload key.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseKeystore = keystorePropertiesFile.exists()
if (hasReleaseKeystore) {
    keystorePropertiesFile.inputStream().use { keystoreProperties.load(it) }
}

android {
    namespace = "com.mawatheq.lawyer"
    // Pinned to 37 rather than flutter.compileSdkVersion (36): permission_handler_android
    // compiles against 37, and compiling against a higher SDK is backward compatible.
    // targetSdk stays at Flutter's default, so runtime behaviour is unchanged.
    compileSdk = 37
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11

        // flutter_local_notifications schedules hearing/appointment reminders through
        // java.time, which is absent below API 26 while this app supports API 24.
        // Without desugaring the scheduled-reminder path fails at runtime.
        isCoreLibraryDesugaringEnabled = true
    }

    kotlin {
        compilerOptions {
            jvmTarget = JvmTarget.JVM_11
        }
    }

    defaultConfig {
        // com.mawatheq.lawyer — deliberately *not* com.mawatheq.app. A different
        // application id means the lawyer app installs alongside the customer app
        // on the same device instead of replacing it, and the two keep separate
        // data directories, separate stored sessions and separate Play listings.
        // It is fixed here: changing it after the first upload orphans the listing.
        applicationId = "com.mawatheq.lawyer"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
        }
    }
}

dependencies {
    // Required by flutter_local_notifications for its java.time usage; the flag that
    // turns it on is set in compileOptions above.
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
