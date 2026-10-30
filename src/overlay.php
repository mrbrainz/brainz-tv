<?php
// OBS browser-source overlay. Fills the source (set its size in OBS), transparent when idle.
// Optional: &caption=0 hides the title/requester caption.
$caption = ($_GET['caption'] ?? '1') !== '0';
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
  </style>
</head>
<body>
  <div id="stage"><div id="player"></div><div id="caption"></div></div>
  <script>
  (() => {
    const token = <?= json_encode((string) $_GET['token']) ?>;
    const showCaption = <?= json_encode($caption) ?>;
    const stage = document.getElementById('stage'), caption = document.getElementById('caption');
    let player = null, ready = false, current = null, paused = false;

    window.onYouTubeIframeAPIReady = () => {
      player = new YT.Player('player', {
        playerVars: {autoplay: 1, controls: 0, disablekb: 1, fs: 0, iv_load_policy: 3, rel: 0, playsinline: 1, mute: 1, origin: location.origin},
        events: {
          onReady: () => { ready = true; poll(); },
          // Video finished before its rotation slot ended: loop it until the server rotates.
          onStateChange: e => { if (e.data === YT.PlayerState.ENDED && !paused) { player.seekTo(0, true); player.playVideo(); } },
          // Unplayable here (e.g. 150: owner blocks embedding, which oEmbed doesn't always reveal):
          // stay transparent and tell the server, which skips to the next video and flags this one.
          onError: () => {
            stage.classList.remove('on');
            if (current) fetch('?api=overlay-error&token=' + encodeURIComponent(token) + '&uid=' + encodeURIComponent(current), {method: 'POST'}).then(poll, () => {});
          },
        },
      });
    };

    function show(now, paused) {
      current = now ? now.uid : null;
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
