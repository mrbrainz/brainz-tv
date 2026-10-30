<?php
// OBS browser-source overlay. Fills the source (set its size in OBS), transparent when idle.
// Optional: &caption=0 hides the title/requester caption; &standby=0 stays transparent when idle
// instead of showing the standby GIF and "add a video" message.
$caption = ($_GET['caption'] ?? '1') !== '0';
$standby = ($_GET['standby'] ?? '1') !== '0';
$site = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>brainz-tv overlay</title>
  <style>
    html, body { margin: 0; height: 100%; overflow: hidden; background: transparent; }
    #stage { position: fixed; inset: 0; opacity: 0; transition: opacity .4s; }
    #stage.on { opacity: 1; }
    #player { width: 100%; height: 100%; display: block; }
    #caption { position: absolute; left: 2vw; bottom: 2vw; max-width: 80%; padding: .5em .8em; border-radius: .4em;
      background: rgba(0, 0, 0, .65); color: #fff; font: 600 clamp(14px, 2.2vw, 32px)/1.3 system-ui, sans-serif; }
    #caption small { display: block; font-weight: 400; opacity: .8; font-size: .7em; }
    #caption:empty { display: none; }
    #standby { position: fixed; inset: 0; opacity: 0; transition: opacity .4s; }
    #standby.on { opacity: 1; }
    #standby img { width: 100%; height: 100%; object-fit: contain; display: block; }
    /* Sized by viewport width so it stays readable in small browser sources (e.g. 376x211). */
    #standby p { position: absolute; left: 50%; bottom: 3vw; transform: translateX(-50%); margin: 0; width: max-content; max-width: 94%;
      padding: .3em .7em; border-radius: .3em; background: rgba(0, 0, 0, .75); color: #fff; text-align: center;
      font: 600 4.5vw/1.15 system-ui, sans-serif; }
    #standby b { display: block; color: #bf94ff; font-size: 2em; font-weight: 800; letter-spacing: .01em; }
  </style>
</head>
<body>
  <div id="stage"><div id="player"></div><div id="caption"></div></div>
<?php if ($standby): ?>
  <div id="standby"><img src="?asset=standby.gif" alt=""><p>Add a video to the queue at<b><?= htmlspecialchars($site, ENT_QUOTES) ?></b></p></div>
<?php endif; ?>
  <script>
  (() => {
    const token = <?= json_encode((string) $_GET['token']) ?>;
    const showCaption = <?= json_encode($caption) ?>;
    const stage = document.getElementById('stage'), caption = document.getElementById('caption');
    const standby = document.getElementById('standby'); // absent with &standby=0
    let player = null, ready = false, current, paused = false; // current starts undefined so the first poll always renders

    window.onYouTubeIframeAPIReady = () => {
      player = new YT.Player('player', {
        playerVars: {autoplay: 1, controls: 0, disablekb: 1, fs: 0, iv_load_policy: 3, cc_load_policy: 0, rel: 0, playsinline: 1, mute: 1, origin: location.origin},
        events: {
          onReady: () => { ready = true; poll(); },
          // Video finished before its rotation slot ended: loop it until the server rotates.
          onStateChange: e => {
            if (e.data === YT.PlayerState.PLAYING) noCaptions();
            if (e.data === YT.PlayerState.ENDED && !paused) { player.seekTo(0, true); player.playVideo(); }
          },
          // The captions module loads after playback starts; turn it off again whenever it appears.
          onApiChange: noCaptions,
          // Unplayable here (e.g. 150: owner blocks embedding, which oEmbed doesn't always reveal):
          // stay transparent and tell the server, which skips to the next video and flags this one.
          onError: () => {
            stage.classList.remove('on');
            if (current) fetch('?api=overlay-error&token=' + encodeURIComponent(token) + '&uid=' + encodeURIComponent(current), {method: 'POST'}).then(poll, () => {});
          },
        },
      });
    };

    // cc_load_policy alone doesn't stop captions a video or viewer has on by default, so unload the module.
    function noCaptions() {
      for (const m of ['captions', 'cc']) try { player.unloadModule(m); } catch {}
      try { player.setOption('captions', 'track', {}); } catch {}
    }

    function show(now, paused) {
      current = now ? now.uid : null;
      if (standby) standby.classList.toggle('on', !now);
      if (!now) {
        stage.classList.remove('on');
        player.stopVideo();
        caption.textContent = '';
        return;
      }
      // When paused, cue (shows the video's poster, no playback) so resume starts at the right point.
      player[paused ? 'cueVideoById' : 'loadVideoById']({videoId: now.id, startSeconds: now.offset});
      caption.textContent = '';
      if (showCaption) {
        caption.append(now.title);
        if (now.name) caption.append(Object.assign(document.createElement('small'), {textContent: 'requested by ' + now.name}));
      }
      stage.classList.add('on');
    }

    // Muted at 0. Unmuted playback works in OBS; normal browsers may block it until the page is clicked.
    function setVolume(volume) {
      if (volume > 0) { player.unMute(); player.setVolume(volume); } else player.mute();
    }

    async function poll() {
      try {
        const r = await fetch('?api=overlay&token=' + encodeURIComponent(token), {cache: 'no-store'});
        const data = await r.json();
        setVolume(data.volume);
        const changed = (data.now ? data.now.uid : null) !== current;
        if (changed) show(data.now, data.paused);
        // Admin pause freezes the video; resume carries on from where it stopped.
        else if (data.now && data.paused !== paused) data.paused ? player.pauseVideo() : player.playVideo();
        paused = data.paused;
      } catch {}
    }
    setInterval(() => ready && poll(), 3000);

    const s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    document.head.append(s);
  })();
  </script>
</body>
</html>
