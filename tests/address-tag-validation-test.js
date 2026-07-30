const assert = require('node:assert/strict');
const validation = require('../js/address-tag-validation.js');

const validOrder = {
  pet: {
    photo: 'data:image/png;base64,iVBORw0KGgo=',
    name: 'Чиж',
    birthday: '15.06.2020',
    breed: 'Пудель',
    address: 'г. Москва',
    phone: '+7 (999) 123-45-67',
  },
  size: {
    key: 'medium',
    userSelected: true,
  },
};
const today = new Date(2026, 6, 27);

function validate(order) {
  return validation.validate(order, { today });
}

assert.equal(validate(validOrder).valid, true, 'корректные данные должны проходить');

const emptyResult = validate({ pet: {}, size: null });
assert.equal(emptyResult.valid, false);
assert.deepEqual(
  Object.keys(emptyResult.errors),
  ['photo', 'size', 'name', 'birthday', 'breed', 'address', 'phone'],
  'все обязательные поля должны быть отмечены'
);

const notSpecifiedResult = validate({
  pet: {
    photo: null,
    name: ' Не указано ',
    birthday: 'Не указано',
    breed: 'НЕ УКАЗАНО',
    address: '   ',
    phone: undefined,
  },
  size: {},
});
assert.equal(notSpecifiedResult.valid, false);
assert.equal(notSpecifiedResult.errors.name, 'Укажите имя питомца');
assert.equal(notSpecifiedResult.errors.breed, 'Укажите породу питомца');

const oneMissingField = structuredClone(validOrder);
oneMissingField.pet.breed = ' ';
assert.deepEqual(Object.keys(validate(oneMissingField).errors), ['breed']);

const invalidDate = structuredClone(validOrder);
invalidDate.pet.birthday = '31.02.2020';
assert.equal(validate(invalidDate).errors.birthday, 'Укажите корректную дату рождения');

const futureDate = structuredClone(validOrder);
futureDate.pet.birthday = '28.07.2026';
assert.equal(validate(futureDate).errors.birthday, 'Дата рождения не может быть в будущем');

const shortPhone = structuredClone(validOrder);
shortPhone.pet.phone = '+7 (123) 45-67';
assert.equal(validate(shortPhone).errors.phone, 'Укажите корректный телефон (не менее 10 цифр)');

const formattedPhone = structuredClone(validOrder);
formattedPhone.pet.phone = '+7 (999) 123-45-67, +7 (921) 765-43-21';
assert.equal(validate(formattedPhone).valid, true);

const implicitSize = structuredClone(validOrder);
delete implicitSize.size.userSelected;
assert.equal(validate(implicitSize).errors.size, 'Выберите размер адресника');

const defaultPhoto = structuredClone(validOrder);
defaultPhoto.pet.photo = '/assets/images/default-pet.png';
assert.equal(validate(defaultPhoto).errors.photo, 'Загрузите фотографию питомца');

console.log('Client address tag validation tests passed');
