# 🤖 Google Docs Form Filler Bot (Laravel)

This Laravel-based automation tool simulates **human-like behavior** while filling out **Google Forms**. Ideal for testing and automating form submissions in a realistic way — including typing delays, randomized input, and smooth interaction flow.

---

## 🧠 Features

- ✅ Laravel backend architecture  
- ✅ Simulates human-like typing and delays  
- ✅ Fills various Google Form input types (text, radio, checkbox, dropdown)  
- ✅ Supports single or multiple submissions  
- ✅ Customizable form logic and responses  
- ✅ Logs submissions for tracking

---

## ⚙️ Tech Stack

- Laravel 10+ (PHP Framework)  
- Goutte (web scraping client) or Puppeteer (via Node if JS browser automation is used)  
- Faker for generating fake input  
- Optional: Node.js + Puppeteer for frontend simulation (if hybrid)

---

## 🚀 Setup Instructions

1. **Clone the project**
   ```bash
   git clone https://github.com/your-username/form-filler-bot-laravel.git
   cd form-filler-bot-laravel
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install && npm run dev
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Edit `.env` to set up database (if needed for logs) or any custom settings.

4. **Run migrations** (optional)
   ```bash
   php artisan migrate
   ```

5. **Run the bot**
   ```bash
   php artisan form:fill
   ```

   Or use a controller/route if you're triggering from a web UI:
   ```bash
   php artisan serve
   ```

---

## 📂 Project Structure

```
app/
├── Console/Commands/FormFill.php      # Artisan command to run the bot
├── Http/Controllers/FormFillController.php  #  Main logic for simulating human form filling
resources/
├── views/form-fill.blade.php           # View logs of submission (optional)
routes/
├── web.php                            # Route to trigger form filler
.env                                    # Environment variables
```

---



## ⚠️ Disclaimer

- This tool is intended **strictly for educational or testing purposes**.
- Do **not** use it for spamming or violating Google's Terms of Service.
- Respect rate limits and use responsibly.

---

## 🧑‍💻 Author

Built with ❤️ in Laravel by [Your Name]  
📫 Connect with me on [LinkedIn](https://www.linkedin.com/in/amrit-acharya-7063472a8/) or [GitHub](https://github.com/Amrit-Acharya1)
