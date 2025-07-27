
<!DOCTYPE html>
<html>
<head>
    <title>Google Form Automation</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-6 rounded shadow-md">
        <h1 class="text-2xl mb-4">Automate Google Form</h1>
        <form action="{{ route('form.submit') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="responses" class="block text-gray-700">Number of Responses:</label>
                <input type="number" id="responses" name="responses" min="1" required
                       class="border rounded w-full py-2 px-3 text-gray-700">
            </div>
            <button type="submit" class="bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-600">
                Submit Responses
            </button>
        </form>
    </div>
</body>
</html>
