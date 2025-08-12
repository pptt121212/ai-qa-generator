<div class="wrap">
    <h1>AI问答生成器 - 设置</h1>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('ai_qa_generator_settings');
        do_settings_sections('ai_qa_generator_settings');
        submit_button();
        ?>
    </form>
</div>
