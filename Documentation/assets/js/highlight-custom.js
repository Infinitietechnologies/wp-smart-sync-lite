// Optional initializer for pages that actually provide a local highlighter and code blocks.
// index.html has only inline code, so it does not load Highlight.js or this initializer.
if (window.hljs && document.querySelector('pre code')) {
    if (typeof window.hljs.highlightAll === 'function') window.hljs.highlightAll();
    else if (typeof window.hljs.initHighlighting === 'function') window.hljs.initHighlighting();
}
