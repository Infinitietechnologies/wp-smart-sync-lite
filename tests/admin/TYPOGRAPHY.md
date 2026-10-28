# Text readability

Status: improved and installed on 28 October 2026.

| Finding | Cause | Fixed | Change |
| --- | --- | --- | --- |
| Helper text, badges and widget metadata are difficult to read | Many labels were 10?12 px with pale secondary colors | Yes | Raise small text to 12?13 px, primary form/log text to 14 px, section headings to 16 px; strengthen muted colors while preserving the compact loading indicator. |
| Floating text can look soft after an animation | Widgets/notices retained identity transforms and dialogs retained a scale transform at rest | Yes | Set resting transforms to none, remove dialog scaling and button lift transforms; retain entrance/exit movement, opacity and reduced-motion behavior. |
| Text faces and heading weights vary between components | Separate font stacks, default control fonts and intermediate 650 weights | Yes | Use the same native system font stack, inherit it in controls and use standard 400/500/600 weights without synthetic faces. Technical JSON/timestamps keep monospace. |

Changes are scoped to SmartSync screens, notifications and confirmations. WordPress navigation and frontend styles are unaffected. All seven changed stylesheets have new cache versions.

Validation: settings workflows passed at five widths; setup passed for six providers at four widths; custom CloudFront workflows and shared header parity passed at five widths. Desktop/mobile screenshots were inspected. Normal-motion checks confirmed transform-free resting text at device scale factors 1 and 1.25. PHP syntax and whitespace checks passed. These are browser rendering/layout checks, not a measurement of the user's physical display. Nothing was committed or staged.
