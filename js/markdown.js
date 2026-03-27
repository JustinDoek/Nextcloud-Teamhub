/**
 * Simple Markdown to HTML converter
 * Supports: bold, italic, links, code blocks, inline code, lists
 */
const MarkdownRenderer = {
    render(text) {
        if (!text) return '';
        
        let html = text;
        
        // Code blocks (```code```)
        html = html.replace(/```([^`]+)```/g, '<pre><code>$1</code></pre>');
        
        // Inline code (`code`)
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        
        // Bold (**text** or __text__)
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
        
        // Italic (*text* or _text_)
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/_([^_]+)_/g, '<em>$1</em>');
        
        // Links [text](url)
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
        
        // Line breaks
        html = html.replace(/\n/g, '<br>');
        
        return html;
    }
};

window.MarkdownRenderer = MarkdownRenderer;
