<?php
if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа
}

// Подключение дополнительных стилей или скриптов в head
add_action('wp_head', function () {
    echo '<!-- Дочерняя тема подключена 2-->';
});

// Шорткод для отображения чата с Google Gemini
function gemini_chat_shortcode() {
    // Подготовка истории чата
    $history = isset($_SESSION['gemini_chat_history']) ? $_SESSION['gemini_chat_history'] : [];

    ob_start(); ?>
    <div id="gemini-chat">
        <textarea id="gemini-user-input" placeholder="Введите сообщение..."></textarea>
        <div id="gemini-chat-controls" style="display: flex; justify-content: center; align-items: center; gap: 10px;">
            <button id="gemini-send-btn">Отправить</button>
            <button id="gemini-clear-btn" style="background-color: red; color: white; border: none; padding: 5px 10px; cursor: pointer;">
                Очистить историю
            </button>
        </div>
        <br>
        <div id="gemini-chat-box">
            <?php if (!empty($history)): ?>
                <?php foreach ($history as $entry): ?>
                    <p><strong><?php echo esc_html($entry['role']); ?>:</strong></p>
                    <div class="chat-message">
                        <?php echo esc_html($entry['content']); ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><strong>Gemini:</strong> Привет! Я ваш виртуальный помощник. Задавайте вопросы, чтобы начать.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('gemini-send-btn').addEventListener('click', async function () {
            const inputField = document.getElementById('gemini-user-input');
            const userMessage = inputField.value.trim();

            if (!userMessage) return;

            inputField.value = '';
            inputField.disabled = true;
            this.disabled = true;

            const chatBox = document.getElementById('gemini-chat-box');

            chatBox.innerHTML += `<p><strong>Вы:</strong> ${userMessage}</p>`;

            try {
                const response = await fetch('<?php echo esc_url(rest_url('gemini/v1/message')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ message: userMessage }),
                });

                const data = await response.json();

                if (response.ok && data.response) {
                    chatBox.innerHTML += `<p><strong>Gemini:</strong> ${data.response}</p>`;
                } else {
                    chatBox.innerHTML += `<p><strong>Ошибка:</strong> ${data.error || 'Не удалось получить ответ от сервера.'}</p>`;
                }
            } catch (error) {
                console.error(error);
                chatBox.innerHTML += `<p><strong>Ошибка:</strong> ${error.message}</p>`;
            } finally {
                inputField.disabled = false;
                this.disabled = false;
            }

            chatBox.scrollTop = chatBox.scrollHeight;
        });

        document.getElementById('gemini-clear-btn').addEventListener('click', async function () {
            const chatBox = document.getElementById('gemini-chat-box');
            chatBox.innerHTML = `<p><strong>Gemini:</strong> История была очищена. Начинайте новый диалог!</p>`;

            try {
                const response = await fetch('<?php echo esc_url(rest_url('gemini/v1/clear-history')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Не удалось очистить историю на сервере.');
                }

                console.log('История успешно очищена.');
            } catch (error) {
                console.error('Ошибка при очистке истории:', error);
                chatBox.innerHTML += `<p><strong>Ошибка:</strong> Не удалось очистить историю на сервере.</p>`;
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('gemini_chat', 'gemini_chat_shortcode');

// Регистрация маршрутов REST API для Google Gemini
add_action('rest_api_init', function () {
    register_rest_route('gemini/v1', '/message', [
        'methods' => 'POST',
        'callback' => 'handle_gemini_message',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('gemini/v1', '/clear-history', [
        'methods' => 'POST',
        'callback' => 'clear_gemini_chat_history',
        'permission_callback' => '__return_true',
    ]);
});

// Обработчик сообщений для Google Gemini
function handle_gemini_message(WP_REST_Request $request) {
    // Получаем сообщение пользователя
    $message = sanitize_text_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_REST_Response(['error' => 'Сообщение не может быть пустым.'], 400);
    }

    // Инициализация сессии, если не запущена
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Подготовка истории чата
    if (!isset($_SESSION['gemini_chat_history'])) {
        $_SESSION['gemini_chat_history'] = [];
    }

    $_SESSION['gemini_chat_history'][] = ['role' => 'Вы', 'content' => $message];

    // Ограничиваем длину истории до 5 сообщений
    if (count($_SESSION['gemini_chat_history']) > 5) {
        $_SESSION['gemini_chat_history'] = array_slice($_SESSION['gemini_chat_history'], -5);
    }

    // API-ключ из wp-config.php
    $api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (empty($api_key)) {
        return new WP_REST_Response(['error' => 'API-ключ не задан.'], 500);
    }

    // Формируем тело запроса к API
    $body = json_encode([
        'model' => 'gemini-1.5-flash', // Укажите нужную модель
        'messages' => array_map(function ($entry) {
            return [
                'role' => ($entry['role'] === 'Вы') ? 'user' : 'assistant',
                'content' => $entry['content'],
            ];
        }, $_SESSION['gemini_chat_history']),
    ], JSON_UNESCAPED_UNICODE);

    // Выполняем запрос к API через cURL
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Проверяем ошибки cURL
    if ($error) {
        return new WP_REST_Response(['error' => 'Ошибка cURL: ' . $error], 500);
    }

    // Проверяем HTTP-статус ответа
    if ($http_code !== 200) {
        return new WP_REST_Response(['error' => 'Ошибка API: HTTP ' . $http_code], $http_code);
    }

    // Парсим ответ API
    $response_decoded = json_decode($response, true);

    if (isset($response_decoded['error']['message'])) {
        return new WP_REST_Response(['error' => $response_decoded['error']['message']], 400);
    }

    $reply = $response_decoded['choices'][0]['message']['content'] ?? 'Ошибка: пустой ответ.';
    $_SESSION['gemini_chat_history'][] = ['role' => 'Gemini', 'content' => $reply];

    return new WP_REST_Response(['response' => $reply], 200);
}


// Обработчик очистки истории чата
function clear_gemini_chat_history(WP_REST_Request $request) {
    if (isset($_SESSION['gemini_chat_history'])) {
        unset($_SESSION['gemini_chat_history']);
    }

    return new WP_REST_Response(['message' => 'История успешно очищена.'], 200);
}
