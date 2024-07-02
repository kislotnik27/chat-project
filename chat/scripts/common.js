$(document).ready(function () {
    const params = new URLSearchParams(window.location.search);
    let platform = params.get('platform');
    let userIdFromUrl = params.get('id');
    loadChatList(platform, userIdFromUrl);
    setupWebSocket();

    $('#back-button').click(function() {
        $('#chat-list').show();
        $('#chat-window').hide();
        $('#back-button').hide();
        history.pushState(null, '', '/');
    });

    $('#file-input').change(function() {
        const fileInput = $(this)[0];
        if (fileInput.files.length > 0) {
            const formData = new FormData();
            $.each(fileInput.files, function(i, file) {
                const fileType = file.type;

                if (fileType.startsWith('image/')) {
                    formData.append('message_type', 'photo');
                } else if (fileType.startsWith('video/')) {
                    formData.append('message_type', 'video');
                } else {
                    alert('Unsupported file type. Please upload an image or video.');
                    return;
                }

                formData.append('file[]', file);
            });
            formData.append('user_id', currentUserId);

            let url;
            if (currentPlatform === 'telegram') {
                url = '/common/telegram/send_message.php';
            } else if (currentPlatform === 'viber') {
                url = '/common/viber/send_message_viber.php';
            }

            $.ajax({
                url: url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(data) {
                    if (data.status === 'success') {
                        $('#file-input').val(''); // Очистка file input после отправки
                        loadMessages(currentUserId, currentPlatform);
                    } else {
                        console.error('Error sending message:', data.message);
                    }
                },
                error: function(error) {
                    console.error('Error:', error);
                }
            });
        }
    });


    // Обработчик для replied-message
    $(document).on('click', '.replied-message', function() {
        const replyTo = $(this).data('reply-to');
        if (replyTo) {
            const target = $('#message-' + replyTo);
            if (target.length) {
                target[0].scrollIntoView({ behavior: 'smooth' });
            }
        }
    });
});

let currentUserId = null;
let currentPlatform = null;
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
        scrollToLastMessage();
        console.log(messageData);
    } else {
        loadChatList(currentPlatform, currentUserId); // Обновляем список чатов при получении новых сообщений
    }
}

function loadChatList(platform, selectedUserId) {
    $.getJSON('/common/get_chats.php', function(data) {
        const chatList = $('#chat-list');
        chatList.html('<div id="back-button" style="display:none;">← Back</div>');
        if (data.status === 'success') {
            chatList.append(data.html);

            const chatItems = chatList.find('.chat-item');
            chatItems.each(function() {
                const chatElement = $(this);
                const chatId = chatElement.data('id');
                const platform_par = chatElement.data('platform').trim(); // Получаем платформу из data-атрибута

                if (chatId == selectedUserId) {
                    chatElement.addClass('selected');
                    currentUserId = chatId;
                    currentPlatform = platform_par;
                    showSendMessageButton(currentPlatform);
                    loadMessages(chatId, currentPlatform);
                }

                chatElement.click(function() {
                    chatItems.removeClass('selected');
                    chatElement.addClass('selected');
                    loadMessages(chatId, platform_par);

                    currentUserId = chatId;
                    currentPlatform = platform_par;
                    showSendMessageButton(currentPlatform);
                    $('#user-id').text(currentUserId);

                    history.pushState(null, '', `?platform=${currentPlatform}&id=${currentUserId}`);
                    if ($(window).width() <= 576) {
                        $('#chat-list').hide();
                        $('#chat-window').show();
                        $('#back-button').show();
                    }
                });
            });

            if (chatList.children().last().length) {
                chatList.children().last()[0].scrollIntoView();
            }
        } else {
            console.error('Error loading chats:', data.message);
        }
    }).fail(function(error) {
        console.error('Error:', error);
    });
}

function loadMessages(userId, platform) {
    $.getJSON(`/common/get_messages.php?user_id=${userId}&platform=${platform}`, function(data) {
        const chatWindow = $('#messages');

        chatWindow.empty(); // Очистка окна чата
        if (data.status === 'success') {
            chatWindow.html(data.html); // Используем HTML из ответа
            $('#user-id').text(userId);

            const replyButtons = chatWindow.find('.reply-button');
            replyButtons.each(function() {
                $(this).click(function() {
                    replyToMessageId = $(this).data('reply-to');
                    $('#message-input').attr('placeholder', `Ответ на сообщение ${replyToMessageId}`);
                });
            });

            // Прокрутка к последнему сообщению
            scrollToLastMessage();
        } else {
            console.error('Error loading messages:', data.message);
        }
    }).fail(function(error) {
        console.error('Error:', error);
    });
}

function displayMessage(message) {
    const chatWindow = $('#messages');
    const messageWrapper = $('<div>').addClass('message');

    const tamMessagesElement = $('<div>').addClass('tam_messages').addClass(message.sender); // Добавляем класс на основе отправителя

    const messageElement = $('<div>');
    let messageContent;
    const senderName = message.sender === 'manager' ? 'Manager' : message.user;

    // Проверка, является ли сообщение ответом
    if (message.reply_to_message_id) {
        // Запрос на сервер для получения сообщения по его message_id
        $.getJSON(`/common/get_message_by_id.php?message_id=${message.reply_to_message_id}`, function(replyMessageData) {
            if (replyMessageData.status === 'success') {
                const repliedMessage = replyMessageData.message;
                const repliedMessageElement = $('<div>').addClass('replied-message');
                repliedMessageElement.html(`<strong>${senderName}:</strong> ${repliedMessage.message}`);
                tamMessagesElement.append(repliedMessageElement);
            } else {
                console.error('Error loading replied message:', replyMessageData.message);
            }
        }).fail(function(error) {
            console.error('Error:', error);
        });
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
    messageElement.html(messageContent);
    tamMessagesElement.append(messageElement);
    messageWrapper.append(tamMessagesElement);
    chatWindow.append(messageWrapper);

    // Добавление кнопки ответа
    const replyButton = $('<button>').text('Ответить');
    replyButton.click(function () {
        replyToMessageId = message.message_id;
        $('#message-input').attr('placeholder', `Ответ на сообщение ${message.message_id}`);
    });
    tamMessagesElement.append(replyButton);

    if (chatWindow.children().last().length) {
        chatWindow.children().last()[0].scrollIntoView();
    }
}


function sendMessage() {
    const messageInput = $('#message-input');
    const message = messageInput.val();
    if (message.trim() !== '') {
        if (currentPlatform === 'telegram') {
            sendTelegramMessage(message);
        } else if (currentPlatform === 'viber') {
            sendViberMessage(message);
        }
    }
}

function sendTelegramMessage(message) {
    const userId = $('#user-id').text();
    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('message', message);
    formData.append('message_type', 'text');

    if (replyToMessageId) {
        formData.append('reply_to_message_id', replyToMessageId);
        replyToMessageId = null;
    }

    $.ajax({
        url: '/common/telegram/send_message.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(data) {
            if (data.status === 'success') {
                $('#message-input').val('');
                loadMessages(userId, 'telegram');
                $('#message-input').attr('placeholder', 'Напишите сообщение...');
            } else {
                console.error('Error sending message:', data.message);
            }
        },
        error: function(error) {
            console.error('Error:', error);
        }
    });
}



function sendViberMessage(message, messageType = 'text', mediaUrl = null) {
    const userId = $('#user-id').text();
    const payload = new FormData();
    payload.append('user_id', userId);
    payload.append('message', message);
    payload.append('message_type', messageType);
    if (replyToMessageId) {
        payload.append('reply_to_message_id', replyToMessageId);
        replyToMessageId = null;
    }

    // Если загружается файл
    const fileInput = $('#file-input')[0];
    if (fileInput.files.length > 0) {
        $.each(fileInput.files, function(i, file) {
            payload.append('file[]', file);
        });
    }

    $.ajax({
        url: '/common/viber/send_message_viber.php',
        type: 'POST',
        data: payload,
        processData: false,
        contentType: false,
        success: function(data) {
            if (data.status === 'success') {
                $('#message-input').val('');
                $('#file-input').val(''); // Очистка file input после отправки
                loadMessages(userId, 'viber');
                $('#message-input').attr('placeholder', 'Напишите сообщение...');
                $('#file-input').wrap('<form>').closest('form').get(0).reset(); // Очистка выбранных файлов
                $('#file-input').unwrap();
            } else {
                console.error('Error sending message:', data.message);
            }
        },
        error: function(error) {
            console.error('Error:', error);
        }
    });
}

function showSendMessageButton(platform) {
    const telegramButton = $('#send-button-telegram');
    const viberButton = $('#send-button-viber');

    if (platform === 'telegram') {
        telegramButton.show();
        viberButton.hide();
    } else if (platform === 'viber') {
        telegramButton.hide();
        viberButton.show();
    }
}

let typing = false;
let timeout;

function timeoutFunction() {
    typing = false;
    socket.send(JSON.stringify({ type: 'typing', user_id: currentUserId, typing: false }));
}

$('#message-input').on('keypress', function(e) {
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

function scrollToLastMessage() {
    const lastMessage = $('#last-message');
    if (lastMessage.length) {
        lastMessage[0].scrollIntoView();
    }
}

$(document).on('click', '.delete-button', function() {
    const messageId = $(this).data('message-id');
    const chatId = $('#user-id').text();

    $.ajax({
        url: '/common/telegram/delete_message.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ chat_id: chatId, message_id: messageId }),
        success: function(data) {
            if (data.status === 'success') {
                // Удалить сообщение из DOM
                $(`#message-${messageId}`).remove();
            } else {
                console.error('Error deleting message:', data.message);
            }
        },
        error: function(error) {
            console.error('Error:', error);
        }
    });
});

function editMessage(chat_id, message_id, new_text) {
    const payload = {
        chat_id: chat_id,
        message_id: message_id,
        text: new_text
    };

    $.ajax({
        url: '/common/telegram/edit_message.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        success: function(data) {
            if (data.status === 'success') {
                // Обновляем текст сообщения в интерфейсе
                const messageElement = $(`#message-${data.message_id} .message-text`);
                messageElement.text(data.new_text);
            } else {
                console.error('Error editing message:', data.message);
            }
        },
        error: function(error) {
            console.error('Error:', error);
        }
    });
}

// Предполагаем, что у каждого сообщения есть data-message-id и класс .message-text
$(document).on('click', '.edit-button', function() {
    const messageId = $(this).data('message-id');
    const chatId = currentUserId; // Используйте правильный способ получения chat_id
    const newText = prompt('Введите новый текст сообщения:');

    if (newText) {
        editMessage(chatId, messageId, newText);
    }
});




