<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$message = "";
$messageType = "danger";

function clean($value) {
    return trim(htmlspecialchars($value, ENT_QUOTES, "UTF-8"));
}

function oldInput($key) {
    return htmlspecialchars($_POST[$key] ?? "", ENT_QUOTES, "UTF-8");
}

$categories = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name ASC");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = $_SESSION["user_id"];
    $title = clean($_POST["title"] ?? "");
    $description = clean($_POST["description"] ?? "");
    $price = filter_var($_POST["price"] ?? "", FILTER_VALIDATE_FLOAT);
    $category_id = filter_var($_POST["category_id"] ?? "", FILTER_VALIDATE_INT);

    if ($title === "" || $description === "" || !$price || !$category_id) {
        $message = "Please complete all fields correctly.";
    } elseif ($price <= 0) {
        $message = "Price must be greater than 0.";
    } elseif (!isset($_FILES["images"]) || empty($_FILES["images"]["name"][0])) {
        $message = "Please upload at least one product image.";
    } else {
        $allowedTypes = ["image/jpeg", "image/png", "image/webp"];
        $uploadedImages = [];
        $imageCount = count($_FILES["images"]["name"]);

        if ($imageCount > 6) {
            $message = "You can upload a maximum of 6 images.";
        } else {
            for ($i = 0; $i < $imageCount; $i++) {
                if ($_FILES["images"]["error"][$i] !== UPLOAD_ERR_OK) {
                    $message = "One or more images failed to upload.";
                    break;
                }

                $fileTmp = $_FILES["images"]["tmp_name"][$i];
                $fileType = mime_content_type($fileTmp);
                $fileSize = $_FILES["images"]["size"][$i];

                if (!in_array($fileType, $allowedTypes)) {
                    $message = "Only JPG, PNG, and WEBP images are allowed.";
                    break;
                }

                if ($fileSize > 2 * 1024 * 1024) {
                    $message = "Each image must be smaller than 2MB.";
                    break;
                }

                $extension = pathinfo($_FILES["images"]["name"][$i], PATHINFO_EXTENSION);
                $imageName = uniqid("product_", true) . "." . strtolower($extension);
                $uploadPath = "uploads/" . $imageName;

                if (!move_uploaded_file($fileTmp, $uploadPath)) {
                    $message = "Image upload failed.";
                    break;
                }

                $uploadedImages[] = $imageName;
            }

            if (empty($message) && count($uploadedImages) > 0) {
                $mainImage = $uploadedImages[0];

                $stmt = $conn->prepare("
                    INSERT INTO products (user_id, category_id, title, description, price, image)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iissds", $user_id, $category_id, $title, $description, $price, $mainImage);

                if ($stmt->execute()) {
                    $product_id = $stmt->insert_id;

                    $imageStmt = $conn->prepare("
                        INSERT INTO product_images (product_id, image_path)
                        VALUES (?, ?)
                    ");

                    foreach ($uploadedImages as $image) {
                        $imageStmt->bind_param("is", $product_id, $image);
                        $imageStmt->execute();
                    }

                    header("Location: profile.php?listed=success");
                    exit();
                } else {
                    $message = "Product could not be listed.";
                }
            }
        }
    }
}

$selectedCategory = $_POST["category_id"] ?? "";
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sell an Item - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=20260514ui1" rel="stylesheet">
</head>
<body class="app-body">

<nav class="app-navbar">
    <div class="nav-brand">C2C Marketplace</div>

    <button class="nav-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="nav-actions">
        <a href="dashboard.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_home", "Home")); ?></a>
        <a href="listings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_marketplace", "Marketplace")); ?></a>
        <a href="sell.php" class="btn-dark"><?php echo htmlspecialchars(c2cT("nav_sell", "Sell")); ?></a>
        <a href="transactions.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_deals", "Deals")); ?></a>
        <a href="messages.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_chat", "Chat")); ?></a>
        <a href="profile.php" class="btn-light-custom"><?php echo htmlspecialchars(c2cT("nav_profile", "Profile")); ?></a>
        <a href="settings.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_settings", "Settings")); ?></a>
        <a href="support.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_support", "Support")); ?></a>
        <a href="logout.php" class="btn-ghost"><?php echo htmlspecialchars(c2cT("nav_logout", "Logout")); ?></a>
    </div>
</nav>

<main class="sell-page">
    <section class="sell-header sell-hero-card">
        <div class="sell-hero-copy">
            <p class="eyebrow">Create Listing</p>
            <h1>Sell an Item</h1>
            <p class="hero-text">
                Upload multiple product images and create a clean listing for buyers.
            </p>
        </div>

        <div class="sell-hero-highlights">
            <div class="sell-highlight">
                <strong>6</strong>
                <span>Max photos</span>
            </div>
            <div class="sell-highlight">
                <strong>2MB</strong>
                <span>Per image</span>
            </div>
            <div class="sell-highlight">
                <strong>Fast</strong>
                <span>Publish flow</span>
            </div>
        </div>
    </section>

    <section class="sell-layout">
        <div class="sell-form-card">
            <div class="sell-form-head">
                <div>
                    <p class="eyebrow">Step 1</p>
                    <h2>Product Details</h2>
                </div>
                <span class="sell-head-chip">Ready to publish</span>
            </div>

            <p class="form-helper">Complete the information below so buyers understand what you are selling.</p>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="sell.php" enctype="multipart/form-data">
                <div class="sell-section">
                    <div class="sell-section-head">
                        <h3 class="sell-section-title">Basic Information</h3>
                        <p class="sell-section-caption">Give buyers the key details they need first.</p>
                    </div>

                    <div class="form-grid-2">
                        <div class="mb-3">
                            <label class="form-label">Product Title</label>
                            <input
                                type="text"
                                name="title"
                                class="form-control"
                                value="<?php echo oldInput("title"); ?>"
                                placeholder="Example: iPhone 11 64GB"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Select category</option>
                                <?php while ($category = $categories->fetch_assoc()): ?>
                                    <option
                                        value="<?php echo $category["category_id"]; ?>"
                                        <?php echo (string)$selectedCategory === (string)$category["category_id"] ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($category["category_name"]); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price</label>
                        <div class="price-field">
                            <span class="price-prefix">R</span>
                            <input
                                type="number"
                                name="price"
                                class="form-control price-input"
                                value="<?php echo oldInput("price"); ?>"
                                min="1"
                                step="0.01"
                                placeholder="2500.00"
                                required
                            >
                        </div>
                        <p class="input-note mt-2 mb-0">Set a fair price that matches the product condition.</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea
                            name="description"
                            class="form-control sell-textarea"
                            rows="6"
                            placeholder="Describe condition, age, defects, accessories included, and reason for selling."
                            required
                        ><?php echo oldInput("description"); ?></textarea>
                        <p class="input-note mt-2 mb-0">Be specific about condition and what is included.</p>
                    </div>
                </div>

                <div class="sell-section">
                    <div class="sell-section-head">
                        <h3 class="sell-section-title">Product Photos</h3>
                        <p class="sell-section-caption">Strong photos increase trust and improve response rates.</p>
                    </div>

                    <div class="sell-label-row">
                        <label class="form-label mb-0">Product Images</label>
                        <span class="upload-count" id="imageCountText" aria-live="polite">No images selected</span>
                    </div>

                    <label for="productImages" class="upload-box">
                        <span class="upload-title">Click to upload images</span>
                        <span class="upload-subtitle">Upload 1 to 6 images. JPG, PNG or WEBP. Max 2MB each.</span>
                    </label>

                    <input
                        type="file"
                        name="images[]"
                        id="productImages"
                        class="upload-input"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        required
                    >
                </div>

                <div class="sell-submit-row">
                    <p class="sell-submit-note">By publishing, your listing becomes visible in the marketplace.</p>
                    <button type="submit" class="btn-auth sell-submit-btn">Publish Listing</button>
                </div>
            </form>
        </div>

        <aside class="sell-preview-card">
            <div class="preview-head">
                <div>
                    <p class="eyebrow">Step 2</p>
                    <h2>Image Gallery</h2>
                </div>
                <span class="preview-count-pill" id="previewCountPill">0 / 6 selected</span>
            </div>

            <div class="multi-preview" id="previewGallery">
                <div class="preview-placeholder">
                    <span>No images selected</span>
                    <p>Your selected product images will appear here before posting.</p>
                </div>
            </div>

            <div class="sell-checklist">
                <h3>Before You Publish</h3>
                <ul>
                    <li>Title is specific and easy to search.</li>
                    <li>Price and condition are accurate.</li>
                    <li>Main image clearly shows the product.</li>
                </ul>
            </div>

            <div class="preview-tips">
                <h3>Tips for better listings</h3>
                <ul>
                    <li>Use clear photos with good lighting.</li>
                    <li>Upload different angles of the item.</li>
                    <li>Show scratches, damage, or defects clearly.</li>
                    <li>Use the first image as your best product photo.</li>
                </ul>
            </div>
        </aside>
    </section>
</main>

<script>
const imageInput = document.getElementById("productImages");
const previewGallery = document.getElementById("previewGallery");
const imageCountText = document.getElementById("imageCountText");
const previewCountPill = document.getElementById("previewCountPill");

function updateImageCount(count) {
    if (imageCountText) {
        imageCountText.textContent = count === 0 ? "No images selected" : `${count} image${count === 1 ? "" : "s"} selected`;
    }

    if (previewCountPill) {
        previewCountPill.textContent = `${count} / 6 selected`;
    }
}

if (imageInput && previewGallery) {
    updateImageCount(0);

    imageInput.addEventListener("change", function () {
        previewGallery.innerHTML = "";

        const files = Array.from(this.files);

        if (files.length === 0) {
            updateImageCount(0);
            previewGallery.innerHTML = `
                <div class="preview-placeholder">
                    <span>No images selected</span>
                    <p>Your selected product images will appear here before posting.</p>
                </div>
            `;
            return;
        }

        if (files.length > 6) {
            updateImageCount(0);
            previewGallery.innerHTML = `
                <div class="preview-placeholder">
                    <span>Too many images</span>
                    <p>Please select a maximum of 6 images.</p>
                </div>
            `;
            this.value = "";
            return;
        }

        updateImageCount(files.length);

        files.forEach((file, index) => {
            const reader = new FileReader();

            reader.onload = function (event) {
                const item = document.createElement("div");
                item.className = index === 0 ? "preview-item preview-main" : "preview-item";

                item.innerHTML = `
                    <img src="${event.target.result}" alt="Product preview">
                    <span class="preview-label">${index === 0 ? "Main image" : "Image " + (index + 1)}</span>
                `;

                previewGallery.appendChild(item);
            };

            reader.readAsDataURL(file);
        });
    });
}
</script>

<script src="js/mobile-nav.js?v=20260507m"></script>
</body>
</html>









