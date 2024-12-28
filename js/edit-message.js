jQuery(document).ready(function ($) {
    $('.edit-message-btn').on('click', function () {
        const commentId = $(this).data('comment-id');
        const currentContent = $(this).closest('.comment-content').text();
        const newContent = prompt('Измените текст сообщения:', currentContent);

        if (newContent && newContent !== currentContent) {
            $.ajax({
                url: editMessageAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'edit_message',
                    nonce: editMessageAjax.nonce,
                    comment_id: commentId,
                    new_content: newContent,
                },
                success: function (response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function () {
                    alert('Ошибка при отправке запроса.');
                },
            });
        }
    });
});
