# Widget motion and responsive page container

Updated 27 October 2026. Implemented in the maintained and installed plugins; no commits created.

The main container on Settings, Cloud Config and Custom CloudFront URL now follows the requested `container-md` limits. These replace the earlier uncapped fluid layout. The fixed sync widget retains its compact/expanded sizes.

| Viewport | Page maximum width |
| --- | --- |
| Below 768px (including 576–767px) | 100% |
| 768–991px | 720px |
| 992–1199px | 960px |
| 1200–1399px | 1140px |
| 1400px and above | 1320px |

Containers center within available WordPress content space and shrink when the admin sidebar/gutters leave less room. Completion keeps the same width as the wizard stepper. Shared CSS variables implement these limits without adding Bootstrap.

## Issue status

| Issue | Cause | Fixed | Result |
| --- | --- | --- | --- |
| Expand/collapse snapped between sizes | Details switched their `hidden` attribute immediately and the card had no size transition. | Yes | Width, grid-row height, opacity and chevron transitions coordinate over 180–260ms. Rapid reversals use the current interpolated state. |
| Two View logs links appeared in failures | The expanded body had its own action row in addition to the terminal footer. | Yes | One shared footer has one link and one button: Logs/Stop while running, Results/Dismiss on success, Logs/Dismiss on failure and Settings/Dismiss after stopping. |
| Dismissal disappeared abruptly | Hiding removed the card before a transition could paint. | Yes | Fade/slide first; hide after the opacity transition, with a timer fallback. Dismissed cards become inert immediately. New run data cancels an old dismissal, and acknowledgement remains persisted. |
| Pages needed the supplied responsive width limits | The prior setup layout deliberately removed page width caps. | Yes | All three plugin page roots use the exact requested breakpoints and centered maximum widths. |
| Expanding the floating widget added blank space to the page | A separate in-flow spacer tracked the fixed card's height to reserve room below the page. | Yes | Removed the spacer, height observer and resize bookkeeping. The card overlays the page without changing content or document height. |

Collapsed details are inert and hidden from assistive technology immediately while they visually close. Keyboard focus returns to the prior page control when dismissing from inside the widget. Reduced-motion preferences disable transitions and dismiss immediately. Details scroll on short mobile viewports while the footer stays accessible. The widget reserves no page space. The floating-behavior correction uses `float-1` asset cache versions.

## Verification

- `npm test --prefix tests/admin`: **76 passed**, including one footer across all four states, controls, keyboard and acknowledgement.
- `node tests/admin/widget-motion-layout-browser.cjs`: real rendered PHP views and maintained assets, with synthetic status data. All three page containers were measured at 320, 575, 576, 767, 768, 991, 992, 1199, 1200, 1399, 1400 and 1920px. Centering, maximum widths and overflow passed.
- The same browser check sampled animation frames to verify intermediate entrance, expansion, collapse and dismissal values; checked rapid reversal, a new run during dismissal, focus return, one footer link, reduced motion and scrollable details at 320×360.
- The floating correction passed 8 focused admin regressions and the browser check; frame samples confirmed unchanged content and document heights during entrance, expansion, collapse and dismissal, with no spacer element remaining.
- `node tests/admin/sync-widget-browser.cjs`: shared-widget navigation/timing, all three pages, stop confirmation, failure acknowledgement, completion, incremental/full-resync controls and mobile layouts passed.
- PHP/JS syntax and `git diff --check` passed. New stylesheet/script cache versions are `container-md-1` and `motion-1`.

The shared rendering fixture loads the existing provider guidance and JSON-upload helpers. Older assertions now follow the shipped request locks, provider-neutral analytics action and idle status monitoring. All checks use synthetic credentials/responses; no live sync or provider operations were invoked.

Five runtime files are synchronized to the installed plugin. Synthetic previews and the machine-readable report are in ignored `dist/widget-motion-layout-preview/`.
