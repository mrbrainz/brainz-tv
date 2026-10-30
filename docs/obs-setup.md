# OBS setup

## Audio
- Overlay videos are **muted by default** (`overlay_volume => 0` in config.php), so they never clash with the radio mix.
- To hear them, set `overlay_volume` to 1–100. The overlay picks up the change within 3 seconds; no OBS refresh needed.
- In the browser source properties, tick **Control audio via OBS**. The video audio then gets its own fader in the OBS mixer, so you can duck or mute it there (and keep it off the stream while still monitoring it).
- Unmuted autoplay works inside OBS. A normal browser tab may stay muted until you click the page; that's browser policy, not a bug.
