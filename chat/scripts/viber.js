function sendMessage() {
    const messageInput = document.getElementById('message-input');
    const message = messageInput.value;
    if (message.trim() !== '') {
        const userId = document.getElementById('user-id').innerText;
        const payload = {
            user_id: userId,
            message: message
        };
        if (replyToMessageId) {
            payload.reply_to_message_id = replyToMessageId;
            replyToMessageId = null;
        }
        fetch('/modules/viber/send_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                messageInput.value = '';
                loadMessages(userId);
                messageInput.placeholder = 'Напишите сообщение...';
            } else {
                console.error('Error sending message:', data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    }
}
