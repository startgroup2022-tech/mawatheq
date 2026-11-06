import 'package:flutter_test/flutter_test.dart';
import 'package:mawatheq_mobile/core/network/lawyer_models.dart';
import 'package:mawatheq_mobile/features/calls/jitsi_shell.dart';

/// Execution 59 — the Jitsi transport.
///
/// The call now runs in a WebView over Jitsi's External API instead of the native
/// SDK, because the SDK's second WebRTC build cannot be merged with the one the
/// built-in peer-to-peer screen already ships. These tests cover the shell the
/// WebView is handed: which script it loads, which options it passes, and — most
/// importantly — that a consultation topic containing a quote cannot break out of
/// the JavaScript it is embedded in.
CallMeeting _meeting({
  String domain = 'meet.jit.si',
  String appId = '',
  String room = 'MawatheqConsult7-abc',
  String subject = 'مواثيق — استشارة قانونية',
  String displayName = 'أ. خالد',
  String? jwt,
  String callType = 'video',
}) =>
    CallMeeting(
      domain: domain,
      room: room,
      subject: subject,
      displayName: displayName,
      moderator: true,
      language: 'ar',
      jwt: jwt,
      appId: appId,
      callType: callType,
    );

void main() {
  group('script URL', () {
    test('a self-hosted deployment serves external_api.js at the domain root', () {
      expect(jitsiScriptUrl(_meeting(domain: 'meet.example.com')),
          'https://meet.example.com/external_api.js');
    });

    test('a JaaS tenant serves it under its app id', () {
      expect(
        jitsiScriptUrl(_meeting(domain: '8x8.vc', appId: 'mawatheq')),
        'https://8x8.vc/mawatheq/external_api.js',
      );
    });

    test('an app id on a non-JaaS domain does not change the path', () {
      // The app id only means "JaaS" on an 8x8.vc host; a self-hosted server that
      // happens to have one set must still serve from the root.
      expect(
        jitsiScriptUrl(_meeting(domain: 'meet.example.com', appId: 'mawatheq')),
        'https://meet.example.com/external_api.js',
      );
    });

    test('the base URL is the meeting domain so the script resolves', () {
      expect(jitsiBaseUrl(_meeting(domain: '8x8.vc')), 'https://8x8.vc');
    });
  });

  group('conference options', () {
    test('a token is only sent when there is one', () {
      expect(jitsiOptions(_meeting()).containsKey('jwt'), isFalse);
      expect(jitsiOptions(_meeting(jwt: 'signed.token.here'))['jwt'], 'signed.token.here');
    });

    test('a voice call starts with the camera muted and a video call does not', () {
      Map<String, dynamic> overrides(CallMeeting m) =>
          (jitsiOptions(m)['configOverrides'] as Map).cast<String, dynamic>();
      expect(overrides(_meeting(callType: 'video'))['startWithVideoMuted'], isFalse);
      expect(overrides(_meeting(callType: 'voice'))['startWithVideoMuted'], isTrue);
    });

    test('recording and livestreaming are hidden because the token refuses them', () {
      final overrides =
          (jitsiOptions(_meeting())['configOverrides'] as Map).cast<String, dynamic>();
      expect(overrides['fileRecordingsEnabled'], isFalse);
      expect(overrides['liveStreamingEnabled'], isFalse);
    });

    test('the display name is passed only when it is known', () {
      expect(jitsiOptions(_meeting(displayName: ''))['userInfo'], isNull);
      expect(
        (jitsiOptions(_meeting(displayName: 'أ. خالد'))['userInfo'] as Map)['displayName'],
        'أ. خالد',
      );
    });
  });

  group('the shell document', () {
    test('carries the room and the event channel', () {
      final html = jitsiShellHtml(_meeting(room: 'MawatheqConsult7-abc'));
      expect(html, contains('"roomName":"MawatheqConsult7-abc"'));
      expect(html, contains('var channel = "$jitsiChannel"'));
      expect(html, contains('window[channel].postMessage'));
    });

    test('a quote in the subject cannot break out of the JavaScript string', () {
      // The subject is the one field an operator can put arbitrary text into (a
      // consultation topic). JSON encoding is what stops it becoming code.
      final hostile = 'x"); window.__pwned = true; //';
      final html = jitsiShellHtml(_meeting(subject: hostile));
      // The quote is escaped, so the payload stays inside the string literal. The
      // text itself is still present — it is data — but the `\")` that would have
      // closed the literal and started a statement is not.
      expect(html, contains(r'x\"); window.__pwned = true; //'));
      expect(html, isNot(contains('"subject":"x");')));
    });

    test('reports an error rather than hanging when the script does not load', () {
      final html = jitsiShellHtml(_meeting());
      expect(html, contains("typeof JitsiMeetExternalAPI !== 'function'"));
      expect(html, contains("tell('error:external_api.js did not load')"));
    });
  });
}
