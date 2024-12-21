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



// Подключение Parsedown 
require_once ABSPATH . '/vendor/erusev/parsedown/Parsedown.php';
//use Parsedown;

/**
 * Функция для преобразования Markdown в HTML
 *
 * @param string $content Markdown-текст.
 * @return string HTML-код.
 */
function parse_markdown_to_html($content)
{
    $parsedown = new Parsedown();
    return $parsedown->text($content);
}

// Шорткод для отображения чата
function chatgpt_chat_shortcode()
{
    

    $history = isset($_SESSION['chat_history']) ? $_SESSION['chat_history'] : [];

    ob_start(); ?>
    <div id="chatgpt-chat">
        <textarea id="user-input" placeholder="Введите сообщение..."></textarea>
        <input type="hidden" id="hidden-file-content" value=""> <!-- Скрытое поле для текста из файла -->
        <div id="chat-controls" style="display: flex; justify-content: center; align-items: center; gap: 10px;">
            <button id="attach-btn" title="Загрузить файл">
                <i class="fas fa-paperclip"></i>
            </button>
            <button id="send-btn">
                <i class="fas fa-envelope"></i>
                <span>Отправить</span>
            </button>
            <button id="clear-btn"
                style="background-color: red; color: white; border: none; padding: 5px 10px; cursor: pointer;">
                Очистить историю
            </button>
        </div>
        <input type="file" id="file-upload" accept=".png,.jpeg,.jpg,.pdf" style="display: none;" />
        <br>
        <div id="chat-box">
            <?php if (!empty($history)): ?>
                <?php foreach ($history as $entry): ?>
                    <p><strong><?php echo esc_html($entry['role']); ?>:</strong>
                    </p>
                    <div class="chat-message">
                        <?php
                        // Преобразование Markdown в HTML
                        echo parse_markdown_to_html($entry['content']);
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><strong>Bot-Lab:</strong> Привет! Я - искусственный интеллект. Пришлите текст или загрузите файл, чтобы
                    начать.</p>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // URL для перенаправления
        const loginUrl = '<?php echo wp_login_url(); ?>';

        const isUserLoggedIn = <?php echo is_user_logged_in() ? 'true' : 'false'; ?>;

        // Отключение действий для неавторизованных пользователей
        const buttons = document.querySelectorAll('#attach-btn, #send-btn, #clear-btn');
        buttons.forEach(button => {
            button.addEventListener('click', (event) => {
                if (!isUserLoggedIn) {
                    event.preventDefault();
                    alert('Вы должны войти в систему для выполнения этого действия.');
                    window.location.href = loginUrl;
                }
            });
        });

        const uploadInput = document.getElementById('file-upload');
        const attachBtn = document.getElementById('attach-btn');
        const hiddenFileContent = document.getElementById('hidden-file-content'); // Скрытое поле для текста из файла

        attachBtn.addEventListener('click', () => {
            if (!isUserLoggedIn) {
                alert('Вы должны войти в систему для загрузки файлов.');
                window.location.href = loginUrl;
                return;
            }
            uploadInput.click();
        });

        uploadInput.addEventListener('change', async () => {
            if (!isUserLoggedIn) {
                alert('Вы должны войти в систему для загрузки файлов.');
                window.location.href = loginUrl;
                return;
            }
            const file = uploadInput.files[0];
            if (!file) return;

            // Проверка размера файла
            if (file.size > 10 * 1024 * 1024) {
                alert('Ошибка: Файл слишком большой. Максимальный размер: 10 МБ.');
                return;
            }

            // Проверка типа файла
            const allowedTypes = ['image/png', 'image/jpeg'];
            if (!allowedTypes.includes(file.type)) {
                alert('Ошибка: Недопустимый формат файла. Допустимы только PNG и JPEG.');
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

                if (response.ok && data.text) {
                    // Сохраняем распознанный текст в скрытом поле
                    hiddenFileContent.value = data.text;

                    // Отображаем сообщение с названием файла в чате
                    const chatBox = document.getElementById('chat-box');
                    chatBox.innerHTML += `<p><strong>Вы:</strong> Файл "${file.name}" добавлен <a href="#" class="delete-file">&lt;удалить файл&gt;</a></p>`;
                    chatBox.scrollTop = chatBox.scrollHeight;
                } else {
                    alert(`Ошибка: ${data.error || 'Не удалось извлечь текст из изображения.'}`);
                }
            } catch (error) {
                console.error(error);
                alert('Ошибка: произошла ошибка при обработке файла.');
            }
        });

        document.getElementById('send-btn').addEventListener('click', async function () {
            if (!isUserLoggedIn) {
                alert('Вы должны войти в систему для отправки сообщений.');
                window.location.href = loginUrl;
                return;
            }
            const inputField = document.getElementById('user-input');
            const userMessage = inputField.value.trim();
            const fileContent = hiddenFileContent.value; // Получаем текст из файла

            if (!userMessage && !fileContent) return; // Ничего не отправляем, если поле пустое и нет текста из файла


            // Объединяем сообщение пользователя с текстом файла
            const combinedMessage = [userMessage, fileContent].filter(Boolean).join('\n\n---\n\n');

            console.log('Отправка данных:', { combinedMessage }); // Отладка

            // Очищаем поле ввода и отключаем кнопки
            inputField.value = '';
            inputField.disabled = true;
            this.disabled = true;

            // Добавляем сообщение пользователя в чат
            const chatBox = document.getElementById('chat-box');
            if (userMessage) {
                chatBox.innerHTML += `<p><strong>Вы:</strong> ${userMessage}</p>`;
            }
            if (fileContent) {
                chatBox.innerHTML += `<p><strong>Вы:</strong> (Текст файла отправлен)</p>`;
            }

            try {
                // Формируем запрос
                const response = await fetch('<?php echo esc_url(site_url('/wp-json/chatgpt/v1/message')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        message: combinedMessage,
                        // file_content: fileContent, // Добавляем текст из файла
                    }),
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
                hiddenFileContent.value = ''; // Очищаем скрытое поле после отправки
            }

            chatBox.scrollTop = chatBox.scrollHeight; // Прокрутка чата вниз
        });
        document.getElementById('clear-btn').addEventListener('click', async function () {
            if (!isUserLoggedIn) {
                alert('Вы должны войти в систему для очистки истории.');
                window.location.href = loginUrl;
                return;
            }
            if (!confirm('Вы уверены, что хотите очистить историю чата? Это действие нельзя отменить.')) {
                return;
            }

            const chatBox = document.getElementById('chat-box');
            chatBox.innerHTML = `<p><strong>Bot-Lab:</strong> История была очищена. Начинайте новый диалог!</p>`;

            try {
                const response = await fetch('<?php echo esc_url(site_url('/wp-json/chatgpt/v1/clear-history')); ?>', {
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
function handle_file_upload(WP_REST_Request $request)
{
    if (empty($_FILES['file'])) {
        return new WP_REST_Response(['error' => 'Файл не передан.'], 400);
    }

    $file = $_FILES['file'];
    $allowed_types = ['image/png', 'image/jpeg'];

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

    $file_path = $upload['file'];

    // Распознаем текст с помощью Tesseract
    $command = escapeshellcmd("tesseract " . escapeshellarg($file_path) . " stdout -l rus+eng");
    $recognized_text = shell_exec($command);

    if (empty($recognized_text)) {
        return new WP_REST_Response(['error' => 'Не удалось распознать текст из изображения.'], 500);
    }

    return new WP_REST_Response(['text' => trim($recognized_text)], 200);
}

// Обработчик REST API для сообщений
function handle_chatgpt_message(WP_REST_Request $request)
{
    $message = sanitize_text_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_REST_Response(['error' => 'Сообщение не может быть пустым.'], 400);
    }

    // Инициализируем историю чата, если её нет
    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [];
    }

    // Добавляем системный промт только один раз
    if (
        empty(array_filter($_SESSION['chat_history'], function ($entry) {
            return $entry['role'] === 'system';
        }))
    ) {
        $_SESSION['chat_history'][] = [
            'role' => 'system',
            'content' => "Представь, что ты опытный врач-диагност с 20-летним стажем. Ты специализируешься на анализе и интерпретации медицинских данных, включая результаты анализов крови, гормонов, мочи и других исследований. Ты даешь подробные и понятные объяснения результатов анализов для пациентов и врачей. Твоя цель:
Проанализировать предоставленные медицинские анализы и подробно разъяснить, что означают их показатели. Указать, какие значения находятся в пределах нормы, какие выходят за пределы нормы, и какие возможные причины отклонений. Объяснить медицинские термины и дать рекомендации, если требуется дальнейшее обследование или консультация. Всегда пиши ответ по русски если тебя не попрошу об ином."
        ];
    }

    // Добавляем сообщение пользователя в историю
    $_SESSION['chat_history'][] = ['role' => 'Вы', 'content' => $message];

    // Ограничиваем историю до последних 5 сообщений для компактности
    if (count($_SESSION['chat_history']) > 5) {
        $_SESSION['chat_history'] = array_slice($_SESSION['chat_history'], -5);
    }



    // Формируем запрос к OpenAI
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : 'ваш-ключ';
    $body = json_encode([
        'model' => 'gpt-4',
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
add_action('rest_api_init', function () {
    register_rest_route('chatgpt/v1', '/clear-history', [
        'methods' => 'POST',
        'callback' => 'clear_chat_history',
        'permission_callback' => '__return_true',
    ]);
});

// Обработчик маршрута очистки истории
function clear_chat_history(WP_REST_Request $request)
{
    // Очистка сессии чата
    if (isset($_SESSION['chat_history'])) {
        unset($_SESSION['chat_history']);
    }

    return new WP_REST_Response(['message' => 'История успешно очищена.'], 200);
}
