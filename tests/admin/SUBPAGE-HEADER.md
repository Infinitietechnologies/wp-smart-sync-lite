# Consistent setup-page headers

Status: fixed. Custom CloudFront URL used a separately styled heading, an extra subtitle and a legacy breadcrumb bar injected only for that page. Cloud setup already used a compact back-link/title header without the breadcrumb bar.

Both setup pages now render the shared `subpage-header.php` component and use common header typography, spacing and SVG back-link styles from enhanced-ui.css. Cloud setup retains its connected-provider context. Custom URL uses the same container spacing and responsive title sizing. Legacy breadcrumb injection is removed, and the changed assets have updated cache versions.

Validation compares computed header/title/link styles between the two actual PHP views. The custom URL browser fixture now uses its real admin page URL and loads ui-improvements.js, so it can catch route-dependent breadcrumb injection. It verifies no breadcrumb bar, the shared back icon, layout across five viewport widths, and the existing inline CloudFront/custom-domain workflow. Eighteen relevant admin cases, both static checks and PHP/JavaScript syntax checks pass. Cloud setup's existing provider/navigation/browser checks also cover its shared header.

This change does not alter cloud provisioning, credentials, domain configuration or sync behavior. No provider operations were performed by the agent.
