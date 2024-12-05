<?php
// Загрузка стилей и скриптов родительской темы
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('child-style', get_stylesheet_directory_uri() . '/style.css', ['parent-style']);
});

// Инициализация сессий
add_action('init', function () {
    if (!session_id()) {
        session_start();
    }
});

add_action('wp_head', function () {
    echo '<!-- Дочерняя тема подключена -->';
});

// Шорткод для отображения чата
function chatgpt_chat_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Вы должны войти в систему, чтобы использовать чат.</p>';
    }

    // Передаем историю сообщений из сессии в JavaScript
    $history = isset($_SESSION['chat_history']) ? $_SESSION['chat_history'] : [];

    ob_start(); ?>
    <div id="chatgpt-chat">
        <div id="chat-box">
            <!-- История чата из PHP -->
            <?php if (!empty($history)) : ?>
                <?php foreach ($history as $entry) : ?>
                    <p><strong><?php echo esc_html($entry['role']); ?>:</strong> <?php echo esc_html($entry['content']); ?></p>
                <?php endforeach; ?>
            <?php else : ?>
                <p><strong>Bot-Lab:</strong> Привет! Я - искусственный интеллект. Пришлите текст или загрузите файл, чтобы начать.</p>
            <?php endif; ?>
        </div>
        <textarea id="user-input" placeholder="Введите сообщение..."></textarea>
        <div id="chat-controls" style="display: flex; justify-content: center; align-items: center; gap: 10px;">
            <button id="attach-btn" title="Загрузить файл">
                <i class="fas fa-paperclip"></i>
            </button>
            <button id="send-btn">
                <i class="fas fa-envelope"></i> 
                <span>Отправить</span>
            </button>
        </div>
        <input type="file" id="file-upload" accept=".png,.jpeg,.jpg,.pdf" style="display: none;" />
    </div>

    <script>
        const uploadInput = document.getElementById('file-upload');
        const attachBtn = document.getElementById('attach-btn');

        attachBtn.addEventListener('click', () => {
            uploadInput.click();
        });

        uploadInput.addEventListener('change', async () => {
            const file = uploadInput.files[0];
            if (!file) return;

            if (file.size > 10 * 1024 * 1024) {
                alert('Файл слишком большой. Максимальный размер: 10 МБ.');
                return;
            }

            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await fetch('<?php echo esc_url(site_url('/wp-json/chatgpt/v1/upload')); ?>', {
                    method: 'POST',
                    body: formData,
                });

                const data = await response.json();

                if (response.ok && data.url) {
                    const chatBox = document.getElementById('chat-box');
                    chatBox.innerHTML += `<p><strong>Вы загрузили файл:</strong> <a href="${data.url}" target="_blank">${data.url}</a></p>`;
                } else {
                    alert(data.error || 'Не удалось загрузить файл.');
                }
            } catch (error) {
                console.error(error);
                alert('Ошибка загрузки файла.');
            }
        });

        document.getElementById('send-btn').addEventListener('click', async function () {
            const inputField = document.getElementById('user-input');
            const input = inputField.value.trim();

            if (!input) return; // Проверяем, что поле не пустое

            // Очищаем поле ввода
            inputField.value = '';
            inputField.disabled = true;
            this.disabled = true;

            const chatBox = document.getElementById('chat-box');
            chatBox.innerHTML += `<p><strong>Вы:</strong> ${input}</p>`; // Добавляем сообщение пользователя

            try {
                const response = await fetch('<?php echo esc_url(site_url('/wp-json/chatgpt/v1/message')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ message: input }),
                });

                const data = await response.json();

                if (response.ok && data.response) {
                    chatBox.innerHTML += `<p><strong>Bot-Lab:</strong> ${data.response}</p>`;
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

            chatBox.scrollTop = chatBox.scrollHeight; // Прокрутка чата вниз
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('chatgpt_chat', 'chatgpt_chat_shortcode');

// Регистрация маршрутов REST API
add_action('rest_api_init', function () {
    register_rest_route('chatgpt/v1', '/message', [
        'methods' => 'POST',
        'callback' => 'handle_chatgpt_message',
        'permission_callback' => '__return_true', // При необходимости добавьте проверки
    ]);

    register_rest_route('chatgpt/v1', '/upload', [
        'methods' => 'POST',
        'callback' => 'handle_file_upload',
        'permission_callback' => '__return_true',
    ]);
});

// Обработчик REST API для загрузки файлов
function handle_file_upload(WP_REST_Request $request) {
    if (empty($_FILES['file'])) {
        return new WP_REST_Response(['error' => 'Файл не передан.'], 400);
    }

    $file = $_FILES['file'];
    $allowed_types = ['image/png', 'image/jpeg', 'application/pdf'];

    if (!in_array($file['type'], $allowed_types)) {
        return new WP_REST_Response(['error' => 'Недопустимый формат файла.'], 400);
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        return new WP_REST_Response(['error' => 'Файл слишком большой. Максимальный размер: 10 МБ.'], 400);
    }

    $upload = wp_handle_upload($file, ['test_form' => false]);

    if (isset($upload['error'])) {
        return new WP_REST_Response(['error' => $upload['error']], 400);
    }

    return new WP_REST_Response(['url' => $upload['url']], 200);
}

// Обработчик REST API для сообщений
function handle_chatgpt_message(WP_REST_Request $request) {
    $message = sanitize_text_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_REST_Response(['error' => 'Сообщение не может быть пустым.'], 400);
    }

    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [];
    }

    $_SESSION['chat_history'][] = ['role' => 'Вы', 'content' => $message];

    if (count($_SESSION['chat_history']) > 5) {
        $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -5);
    }

    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : 'ваш-ключ';
    $body = json_encode([
        'model' => 'gpt-4o',
        'messages' => array_map(function ($entry) {
            return [
                'role' => ($entry['role'] === 'Вы') ? 'user' : 'assistant',
                'content' => $entry['content'],
            ];
        }, $_SESSION['chat_history']),
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
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

    if ($error) {
        return new WP_REST_Response(['error' => 'Ошибка cURL: ' . $error], 500);
    }

    if ($http_code !== 200) {
        return new WP_REST_Response(['error' => 'Ошибка API: HTTP ' . $http_code], $http_code);
    }

    $response_decoded = json_decode($response, true);

    if (isset($response_decoded['error']['message'])) {
        return new WP_REST_Response(['error' => $response_decoded['error']['message']], 400);
    }

    $reply = $response_decoded['choices'][0]['message']['content'] ?? 'Ошибка: пустой ответ.';

    $_SESSION['chat_history'][] = ['role' => 'Bot-Lab', 'content' => $reply];

    return new WP_REST_Response(['response' => $reply], 200);
}
