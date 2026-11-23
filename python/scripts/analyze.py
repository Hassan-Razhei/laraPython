import sys
import larabridge

# 1. Receive structured payload from Laravel
data = larabridge.payload()
text = data.get('text', '')

# 2. Perform Python operations
words = text.split() if text else []
sentiment = "positive" if any(w in text for w in ["رائع", "ممتاز", "جميل"]) else "neutral"

# 3. Respond back with structured JSON
larabridge.respond({
    "status": "success",
    "received_text": text,
    "char_count": len(text),
    "words_count": len(words),
    "sentiment": sentiment,
    "python_version": sys.version.split()[0],
})

