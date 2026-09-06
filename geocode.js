const  axios = re

const response = await axios.get('https://api.neshan.org/geocoding/v1', {


    params: {
        json: JSON.stringify({
            address: 'بلوار فضل نیشابور'
            // 可以添加 city 和 province 来提高精度
        })
    },
    headers: {
        'Api-Key': 'web.540ad3d4b02f4ac6b9b90051a2d040f8'
    }
});

console.log(response);