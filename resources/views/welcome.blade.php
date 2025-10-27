<html>
    <head>
        <title>Welcome</title>
    </head>
    <body id=app>
        <h1>ghg</h1>
    </body>
</html>


<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.11.0/axios.min.js" integrity="sha512-h9644v03pHqrIHThkvXhB2PJ8zf5E9IyVnrSfZg8Yj8k4RsO4zldcQc4Bi9iVLUCCsqNY0b4WXVV4UB+wbWENA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>

console.log('Hello World!');
    axios.get('api/v1/admin/partners')
        .then(function (response) {
            console.log(response.data);
        })
        .catch(function (error) {
            console.log(error);
        });

</script>
