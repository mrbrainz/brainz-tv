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
    let player = null, ready = false, current = null;

    window.onYouTubeIframeAPIReady = () => {
      player = new YT.Player('player', {
        playerVars: {autoplay: 1, controls: 0, disablekb: 1, fs: 0, iv_load_policy: 3, rel: 0, playsinline: 1, mute: 1, origin: location.origin},
        events: {
          onReady: () => { ready = true; poll(); },
          // Video finished before its rotation slot ended: hide rather than show YouTube's end screen.
          onStateChange: e => { if (e.data === YT.PlayerState.ENDED) stage.classList.remove('on'); },
        },
      });
    };

    function show(now) {
      current = now ? now.uid : null;
      if (!now) {
        stage.classList.remove('on');
        player.stopVideo();
        caption.textContent = '';
        return;
      }
      player.loadVideoById({videoId: now.id, startSeconds: now.offset});
      caption.textContent = '';
      if (showCaption) {
        caption.append(now.title);
        if (now.name) caption.append(Object.assign(document.createElement('small'), {textContent: 'requested by ' + now.name}));
      }
      stage.classList.add('on');
    }

    async function poll() {
      try {
        const r = await fetch('?api=overlay&token=' + encodeURIComponent(token), {cache: 'no-store'});
        const {now} = await r.json();
        if ((now ? now.uid : null) !== current) show(now);
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
