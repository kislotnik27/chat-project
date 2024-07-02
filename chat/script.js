document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const userIdFromUrl = params.get('user_id');
    loadChatList(userIdFromUrl);
    setupWebSocket();
    const backButton = document.getElementById('back-button');
    backButton.addEventListener('click', function() {
        document.getElementById('chat-list').style.display = 'block';
        document.getElementById('chat-window').style.display = 'none';
        backButton.style.display = 'none';
        history.pushState(null, '', '/');
    });
});

let currentUserId = null;
let socket;
let replyToMessageId = null;

function setupWebSocket() {
    socket = new WebSocket('wss://ws.elaliza.com');

    socket.onmessage = function(event) {
        let messageData;
        if (typeof event.data === 'string') {
            messageData = JSON.parse(event.data);
        } else if (event.data instanceof Blob) {
            const reader = new FileReader();
            reader.onload = function() {
                messageData = JSON.parse(reader.result);
                processWebSocketMessage(messageData);
            };
            reader.readAsText(event.data);
            return;
        }

        processWebSocketMessage(messageData);
    };

    socket.onopen = function(event) {
        console.log('WebSocket connection established');
    };

    socket.onclose = function(event) {
        console.log('WebSocket connection closed');
        setTimeout(setupWebSocket, 5000); // Попробуйте переподключиться через 5 секунд
    };
}

function processWebSocketMessage(messageData) {
    console.log('Received WebSocket message:', messageData);

    if (messageData.user_id == currentUserId) {
        displayMessage(messageData);
        console.log(messageData);
    } else {
        loadChatList(currentUserId); // Обновляем список чатов при получении новых сообщений
    }
}

function loadChatList(selectedUserId) {
    fetch('/modules/telegram/get_chats.php')
        .then(response => response.json())
        .then(data => {
            const chatList = document.getElementById('chat-list');
            chatList.innerHTML = '<div id="back-button" style="display:none;">← Back</div>';
            if (data.status === 'success') {
                data.chats.forEach(chat => {
                    const chatElement = document.createElement('div');
                    chatElement.classList.add('chat-item');
                    if (chat.chat_id == selectedUserId) {
                        chatElement.classList.add('selected');
                        currentUserId = chat.chat_id;
                        loadMessages(chat.chat_id);
                    }
                    chatElement.innerHTML = `
                        <img src="avatars/${chat.avatar}" alt="Avatar">
                        <div class="chat-info">
                            <div class="name">${chat.name}</div>
                            <div class="last-message">${chat.last_message}</div>
                            <div class="platform">${chat.platform}</div>
                        </div>
                    `;
                    chatElement.onclick = function () {
                        const selectedChatItems = document.querySelectorAll('.chat-item.selected');
                        selectedChatItems.forEach(item => item.classList.remove('selected'));
                        chatElement.classList.add('selected');
                        loadMessages(chat.chat_id); // Используем chat_id для загрузки сообщений
                        
                        currentUserId = chat.chat_id; // Устанавливаем текущий выбранный user_id
                        document.getElementById('user-id').innerText = currentUserId;

                        // Обновляем URL без перезагрузки страницы
                        history.pushState(null, '', `?user_id=${chat.chat_id}`);
                        if (window.innerWidth <= 576) {
                            document.getElementById('chat-list').style.display = 'none';
                            document.getElementById('chat-window').style.display = 'block';
                            document.getElementById('back-button').style.display = 'block';
                        }
                    };
                    chatList.appendChild(chatElement);
                });

                if (chatList.lastChild) {
                    chatList.lastChild.scrollIntoView();
                }
            } else {
                console.error('Error loading chats:', data.message);
            }
        })
        .catch(error => console.error('Error:', error));
}

function loadMessages(userId) {
    fetch(`/modules/telegram/get_messages.php?user_id=${userId}`)
        .then(response => response.json())
        .then(data => {
            const chatWindow = document.getElementById('messages');
            chatWindow.innerHTML = ''; // Очистка окна чата
            if (data.status === 'success') {
                data.messages.forEach(message => {
                    displayMessage(message);
                });

                if (chatWindow.lastChild) {
                    chatWindow.lastChild.scrollIntoView();
                }
                document.getElementById('user-id').innerText = userId;
            } else {
                console.error('Error loading messages:', data.message);
            }
        })
        .catch(error => console.error('Error:', error));
}

function displayMessage(message) {
    const chatWindow = document.getElementById('messages');
    const messageWrapper = document.createElement('div');
    messageWrapper.classList.add('message');

    const tamMessagesElement = document.createElement('div');
    tamMessagesElement.classList.add('tam_messages', message.sender); // Добавляем класс на основе отправителя

    const messageElement = document.createElement('div');
    let messageContent;
    const senderName = message.sender === 'manager' ? 'Manager' : message.user;

    // Проверка, является ли сообщение ответом
    if (message.reply_to_message_id) {
        // Запрос на сервер для получения сообщения по его message_id_tg
        fetch(`/modules/telegram/get_message_by_id.php?message_id_tg=${message.reply_to_message_id}`)
            .then(response => response.json())
            .then(replyMessageData => {
                if (replyMessageData.status === 'success') {
                    const repliedMessage = replyMessageData.message;
                    const repliedMessageElement = document.createElement('div');
                    repliedMessageElement.classList.add('replied-message');
                    repliedMessageElement.innerHTML = `<strong>${senderName}:</strong> ${repliedMessage.message}`;
                    tamMessagesElement.appendChild(repliedMessageElement);
                } else {
                    console.error('Error loading replied message:', replyMessageData.message);
                }
            })
            .catch(error => console.error('Error:', error));
    }

    switch (message.message_type) {
        case 'text':
            messageContent = `<strong>${senderName}:</strong> ${message.message}`;
            break;
        case 'photo':
            messageContent = `<strong>${senderName}:</strong> <img src="${message.media_url}" alt="Photo">`;
            break;
        case 'video':
            messageContent = `<strong>${senderName}:</strong> <video controls src="${message.media_url}"></video>`;
            break;
        case 'document':
            messageContent = `<strong>${senderName}:</strong> <a href="${message.media_url}" target="_blank">Document</a>`;
            break;
        case 'audio':
            messageContent = `<strong>${senderName}:</strong> <audio controls src="${message.media_url}"></audio>`;
            break;
        case 'voice':
            messageContent = `<strong>${senderName}:</strong> <audio controls src="${message.media_url}"></audio>`;
            break;
        default:
            messageContent = `<strong>${senderName}:</strong> Unsupported message type`;
    }
    messageElement.innerHTML += messageContent;
    tamMessagesElement.appendChild(messageElement);
    messageWrapper.appendChild(tamMessagesElement);
    chatWindow.appendChild(messageWrapper);

    // Добавление кнопки ответа
    const replyButton = document.createElement('button');
    replyButton.innerText = 'Ответить';
    replyButton.onclick = function () {
        replyToMessageId = message.message_id_tg;
        document.getElementById('message-input').placeholder = `Ответ на сообщение ${message.message_id_tg}`;
    };
    tamMessagesElement.appendChild(replyButton);

    if (chatWindow.lastChild) {
        chatWindow.lastChild.scrollIntoView();
    }
}

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
        fetch('/modules/telegram/send_message.php', {
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

let typing = false;
let timeout;

function timeoutFunction() {
    typing = false;
    socket.send(JSON.stringify({ type: 'typing', user_id: currentUserId, typing: false }));
}

document.getElementById('message-input').addEventListener('keypress', function(e) {
    if (e.which !== 13) { // игнорируем Enter
        if (typing === false) {
            typing = true;
            socket.send(JSON.stringify({ type: 'typing', user_id: currentUserId, typing: true }));
            timeout = setTimeout(timeoutFunction, 3000);
        } else {
            clearTimeout(timeout);
            timeout = setTimeout(timeoutFunction, 3000);
        }
    }
});

socket.onmessage = function(event) {
    let messageData;
    if (typeof event.data === 'string') {
        messageData = JSON.parse(event.data);
    } else if (event.data instanceof Blob) {
        const reader = new FileReader();
        reader.onload = function() {
            messageData = JSON.parse(reader.result);
            processWebSocketMessage(messageData);
        };
        reader.readAsText(event.data);
        return;
    }

    processWebSocketMessage(messageData);
};

function processWebSocketMessage(messageData) {
    console.log('Received WebSocket message:', messageData);

    if (messageData.type === 'typing' && messageData.user_id !== currentUserId) {
        const typingIndicator = document.getElementById('typing-indicator');
        if (messageData.typing) {
            typingIndicator.innerText = 'Typing...';
            typingIndicator.style.display = 'block';
        } else {
            typingIndicator.style.display = 'none';
        }
    } else if (messageData.user_id == currentUserId) {
        displayMessage(messageData);
        console.log(messageData);
    } else {
        loadChatList(currentUserId); // Обновляем список чатов при получении новых сообщений
    }
}
