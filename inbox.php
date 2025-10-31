<?php
require_once 'config.php';
requireLogin();

$currentUser = getCurrentUser($pdo);
$view = $_GET['view'] ?? 'inbox';

// Fetch emails based on view
if ($view === 'inbox') {
    $stmt = $pdo->prepare("
        SELECT e.*, u.username as sender_username, u.email as sender_email 
        FROM emails e 
        JOIN users u ON e.sender_id = u.id 
        WHERE e.receiver_id = ? AND e.is_deleted_by_receiver = FALSE 
        ORDER BY e.sent_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
} elseif ($view === 'sent') {
    $stmt = $pdo->prepare("
        SELECT e.*, u.username as receiver_username, u.email as receiver_email  
        FROM emails e 
        JOIN users u ON e.receiver_id = u.id 
        WHERE e.sender_id = ? AND e.is_deleted_by_sender = FALSE 
        ORDER BY e.sent_at DESC
    ");
    $stmt->execute([$currentUser['id']]);
} elseif ($view === 'deleted') {
    $stmt = $pdo->prepare("
        SELECT e.*, 
               sender.username as sender_username, sender.email as sender_email,
               receiver.username as receiver_username, receiver.email as receiver_email
        FROM emails e 
        JOIN users sender ON e.sender_id = sender.id 
        JOIN users receiver ON e.receiver_id = receiver.id 
        WHERE (e.receiver_id = ? AND e.is_deleted_by_receiver = TRUE) 
           OR (e.sender_id = ? AND e.is_deleted_by_sender = TRUE)
        ORDER BY e.sent_at DESC
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id']]);
}

$emails = $stmt->fetchAll();

// Handle AJAX requests for marking as read and deleting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'mark_read' && isset($_POST['email_id'])) {
        $stmt = $pdo->prepare("UPDATE emails SET is_read = TRUE WHERE id = ? AND receiver_id = ?");
        $stmt->execute([$_POST['email_id'], $currentUser['id']]);
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($_POST['action'] === 'delete' && isset($_POST['email_id'])) {
        $emailId = $_POST['email_id'];
        
        // Check if user is sender or receiver
        $stmt = $pdo->prepare("SELECT sender_id, receiver_id FROM emails WHERE id = ?");
        $stmt->execute([$emailId]);
        $email = $stmt->fetch();
        
        if ($email) {
            if ($email['sender_id'] == $currentUser['id']) {
                $stmt = $pdo->prepare("UPDATE emails SET is_deleted_by_sender = TRUE WHERE id = ?");
                $stmt->execute([$emailId]);
            } elseif ($email['receiver_id'] == $currentUser['id']) {
                $stmt = $pdo->prepare("UPDATE emails SET is_deleted_by_receiver = TRUE WHERE id = ?");
                $stmt->execute([$emailId]);
            }
        }
        
        echo json_encode(['success' => true]);
        exit();
    }
}

// Get unread count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM emails WHERE receiver_id = ? AND is_read = FALSE AND is_deleted_by_receiver = FALSE");
$stmt->execute([$currentUser['id']]);
$unreadCount = $stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbox - Gmail Clone</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-image: 
                linear-gradient(rgba(102, 126, 234, 0.9), rgba(118, 75, 162, 0.9)),
                url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600"><path d="M0,300 Q300,100 600,300 T1200,300 L1200,600 L0,600 Z" fill="%23667eea" opacity="0.3"/><path d="M0,350 Q300,200 600,350 T1200,350 L1200,600 L0,600 Z" fill="%23764ba2" opacity="0.3"/><path d="M0,400 Q300,250 600,400 T1200,400 L1200,600 L0,600 Z" fill="%23667eea" opacity="0.2"/></svg>');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            min-height: 100vh;
        }
        
        .container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            gap: 20px;
            padding: 0 20px;
            height: calc(100vh - 40px);
        }
        
        .sidebar {
            width: 250px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
        }
        
        .logo {
            margin-bottom: 30px;
            text-align: center;
        }
        
        .logo h2 {
            color: #667eea;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #666;
            font-size: 12px;
        }
        
        .compose-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .compose-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .nav-item {
            padding: 12px 15px;
            margin-bottom: 5px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            text-decoration: none;
            font-size: 14px;
        }
        
        .nav-item:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .nav-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
        }
        
        .nav-item .icon {
            font-size: 18px;
        }
        
        .nav-item .badge {
            margin-left: auto;
            background: #ff4444;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .user-info {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .user-info p {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .user-info strong {
            color: #333;
            font-size: 14px;
        }
        
        .logout-btn {
            margin-top: 10px;
            background: #ff4444;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .logout-btn:hover {
            background: #cc0000;
        }
        
        .main-content {
            flex: 1;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow-y: auto;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .email-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .email-item {
            background: white;
            border: 2px solid #f0f0f0;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .email-item:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }
        
        .email-item.unread {
            background: #f8f9ff;
            border-color: #667eea;
        }
        
        .email-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .email-sender {
            font-weight: 600;
            color: #333;
            font-size: 15px;
        }
        
        .email-time {
            color: #999;
            font-size: 12px;
        }
        
        .email-subject {
            font-weight: 600;
            color: #667eea;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .email-body {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .delete-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #ff4444;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .email-item:hover .delete-btn {
            opacity: 1;
        }
        
        .delete-btn:hover {
            background: #cc0000;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: #666;
        }
        
        .empty-state p {
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                height: auto;
            }
            
            .sidebar {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">
                <h2>📧 Gmail</h2>
                <p>Clone</p>
            </div>
            
            <a href="send_email.php" class="compose-btn">
                <span>✏️</span> Compose
            </a>
            
            <a href="inbox.php?view=inbox" class="nav-item <?php echo $view === 'inbox' ? 'active' : ''; ?>">
                <span class="icon">📥</span>
                <span>Inbox</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="inbox.php?view=sent" class="nav-item <?php echo $view === 'sent' ? 'active' : ''; ?>">
                <span class="icon">📤</span>
                <span>Sent</span>
            </a>
            
            <a href="inbox.php?view=deleted" class="nav-item <?php echo $view === 'deleted' ? 'active' : ''; ?>">
                <span class="icon">🗑️</span>
                <span>Deleted</span>
            </a>
            
            <div class="user-info">
                <p>Logged in as:</p>
                <strong><?php echo htmlspecialchars($currentUser['username']); ?></strong>
                <form action="logout.php" method="POST">
                    <button type="submit" class="logout-btn">Logout</button>
                </form>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h1>
                    <?php 
                    if ($view === 'inbox') echo '📥 Inbox';
                    elseif ($view === 'sent') echo '📤 Sent Emails';
                    elseif ($view === 'deleted') echo '🗑️ Deleted Emails';
                    ?>
                </h1>
                <p>
                    <?php 
                    if ($view === 'inbox') echo 'Your received messages';
                    elseif ($view === 'sent') echo 'Messages you have sent';
                    elseif ($view === 'deleted') echo 'Deleted messages';
                    ?>
                </p>
            </div>
            
            <div class="email-list">
                <?php if (empty($emails)): ?>
                    <div class="empty-state">
                        <div class="icon">📭</div>
                        <h3>No emails here</h3>
                        <p>
                            <?php 
                            if ($view === 'inbox') echo 'Your inbox is empty. When someone sends you an email, it will appear here.';
                            elseif ($view === 'sent') echo 'You haven\'t sent any emails yet.';
                            elseif ($view === 'deleted') echo 'No deleted emails.';
                            ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($emails as $email): ?>
                        <div class="email-item <?php echo (!$email['is_read'] && $view === 'inbox') ? 'unread' : ''; ?>" 
                             data-email-id="<?php echo $email['id']; ?>"
                             onclick="markAsRead(<?php echo $email['id']; ?>)">
                            <button class="delete-btn" onclick="event.stopPropagation(); deleteEmail(<?php echo $email['id']; ?>)">
                                Delete
                            </button>
                            <div class="email-header">
                                <div class="email-sender">
                                    <?php 
                                    if ($view === 'inbox' || $view === 'deleted') {
                                        echo htmlspecialchars($email['sender_username']);
                                    } else {
                                        echo 'To: ' . htmlspecialchars($email['receiver_username']);
                                    }
                                    ?>
                                </div>
                                <div class="email-time">
                                    <?php echo timeAgo($email['sent_at']); ?>
                                </div>
                            </div>
                            <div class="email-subject">
                                <?php echo htmlspecialchars($email['subject']); ?>
                            </div>
                            <div class="email-body">
                                <?php echo nl2br(htmlspecialchars($email['body'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function markAsRead(emailId) {
            fetch('inbox.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_read&email_id=' + emailId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const emailItem = document.querySelector(`[data-email-id="${emailId}"]`);
                    if (emailItem) {
                        emailItem.classList.remove('unread');
                    }
                }
            });
        }
        
        function deleteEmail(emailId) {
            if (confirm('Are you sure you want to delete this email?')) {
                fetch('inbox.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete&email_id=' + emailId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
        }
    </script>
</body>
</html>
