import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing material is supplied by the owner and never committed: android/key.properties
// points at a keystore (.jks/.keystore, both gitignored). When the file is absent — a plain
// `flutter build apk --release` on a fresh clone — the release build falls back to the debug
// signature so the build still works, exactly as before this change.
//
// `Properties` is imported above rather than written as `java.util.Properties`: inside a
// Project script `java` resolves to the Java plugin extension, not the JDK package, so the
// qualified form does not compile here (it does in settings.gradle.kts, which has no such
// extension).
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseKeystore = keystorePropertiesFile.exists()
if (hasReleaseKeystore) {
    keystorePropertiesFile.inputStream().use { keystoreProperties.load(it) }
}

android {
    namespace = "com.mawatheq.app"
    // Pinned to 37 rather than flutter.compileSdkVersion (36): permission_handler_android
    // compiles against 37, and compiling against a higher SDK is backward compatible.
    // targetSdk stays at Flutter's default, so runtime behaviour is unchanged — this
    // only widens the API surface available at compile time.
    compileSdk = 37
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11

        // flutter_local_notifications schedules notifications through java.time, which
        // is not present on the API levels this app supports (minSdk 21). Without
        // desugaring the scheduled-reminder path fails at runtime on older devices.
        // The plugin requires desugaring even when only immediate notifications are
        // used, so this is not optional while the dependency is present.
        isCoreLibraryDesugaringEnabled = true
    }

    // compilerOptions replaces the deprecated kotlinOptions DSL; Kotlin 2.x warns on the
    // old form and AGP 9 removes it.
    kotlin {
        compilerOptions {
            jvmTarget = JvmTarget.JVM_11
        }
    }

    defaultConfig {
        // com.mawatheq.app — the production identifier. Changing it after the first Play
        // upload would orphan the listing, so it is fixed here.
        // It must also match the "package_name" in the domain's
        // /.well-known/assetlinks.json or Android App Links verification fails.
        applicationId = "com.mawatheq.app"
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
            // The app holds no credential, so the debug keystore is a valid signature for a
            // testable release build. A Play upload needs the owner's upload key: drop a real
            // keystore in and replace this with a release signingConfig before publishing.
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
    // turns it on is set in compileOptions above. 2.1.4 is the version the plugin
    // documents and ships against.
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
