import 'dart:convert';

import '../../core/network/lawyer_models.dart';

/// The JavaScript channel Jitsi's lifecycle events are reported on.
const String jitsiChannel = 'MawatheqJitsi';

/// The External API script for a deployment.
///
/// A JaaS tenant serves its script under its app id
/// (`8x8.vc/<appId>/external_api.js`); a self-hosted or meet.jit.si deployment
/// serves it at the domain root. Getting this wrong is a blank call rather than an
/// error, so it is a named function with its own test rather than a ternary
/// buried in the widget.
String jitsiScriptUrl(CallMeeting m) {
  final jaas = m.appId.isNotEmpty && m.domain.contains('8x8.vc');
  return jaas
      ? 'https://${m.domain}/${m.appId}/external_api.js'
      : 'https://${m.domain}/external_api.js';
}

/// The origin the shell document is served from.
///
/// Jitsi's External API resolves its own script and iframe relative to the page
/// origin, and a `null` origin (the default for a string-loaded page) makes the
/// script load fail.
String jitsiBaseUrl(CallMeeting m) => 'https://${m.domain}';

/// The conference options handed to `JitsiMeetExternalAPI`.
///
/// Recording, livestreaming and file upload are refused server-side by the JWT
/// feature claims; hiding the controls here means no one is offered a button the
/// token would reject.
Map<String, dynamic> jitsiOptions(CallMeeting m) => <String, dynamic>{
      'roomName': m.room,
      if (m.hasToken) 'jwt': m.jwt,
      'configOverrides': {
        'subject': m.subject,
        'startWithAudioMuted': false,
        'startWithVideoMuted': m.callType != 'video',
        'prejoinPageEnabled': false,
        'disableDeepLinking': true,
        'defaultLanguage': m.language,
        'fileRecordingsEnabled': false,
        'liveStreamingEnabled': false,
        'toolbarButtons': [
          'microphone',
          'camera',
          'closedcaptions',
          'desktop',
          'chat',
          'raisehand',
          'tileview',
          'hangup',
        ],
      },
      'interfaceConfigOverrides': {
        'MOBILE_APP_PROMO': false,
        'SHOW_JITSI_WATERMARK': false,
      },
      if (m.displayName.isNotEmpty)
        'userInfo': {'displayName': m.displayName},
    };

/// Builds the local document that hosts the meeting.
///
/// Everything interpolated is JSON-encoded, so a room name or a subject that
/// contains a quote cannot break out of the JavaScript string and inject code. The
/// subject and the display name are the only free text here, and both come from the
/// server's own authorised payload — but the server does not control whether a
/// consultation topic contains a quote, so the encoding is what makes this safe.
///
/// The meeting is driven through the External API rather than by loading the join
/// URL directly because that is what makes the lifecycle observable: the events
/// arrive as messages on [jitsiChannel], so the screen can report the hangup once
/// and pop itself.
String jitsiShellHtml(CallMeeting m) {
  final script = jitsiScriptUrl(m);
  return '''
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <style>html,body,#stage{margin:0;padding:0;height:100%;width:100%;background:#000;}</style>
</head>
<body>
  <div id="stage"></div>
  <script src=${jsonEncode(script)}></script>
  <script>
    (function () {
      var channel = ${jsonEncode(jitsiChannel)};
      function tell(msg) { try { window[channel].postMessage(msg); } catch (e) {} }
      if (typeof JitsiMeetExternalAPI !== 'function') {
        tell('error:external_api.js did not load');
        return;
      }
      var options = ${jsonEncode(jitsiOptions(m))};
      options.parentNode = document.getElementById('stage');
      try {
        var api = new JitsiMeetExternalAPI(${jsonEncode(script)}, options);
        api.addEventListener('readyToClose', function () { tell('readyToClose'); });
        api.addEventListener('videoConferenceLeft', function () { tell('left'); });
        api.addEventListener('errorOccurred', function (e) {
          tell('error:' + ((e && e.error && e.error.message) || 'conference error'));
        });
      } catch (e) {
        tell('error:' + (e && e.message ? e.message : 'could not start the conference'));
      }
    })();
  </script>
</body>
</html>
''';
}
